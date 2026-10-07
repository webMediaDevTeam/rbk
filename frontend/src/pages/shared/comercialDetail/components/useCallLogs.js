import { useEffect, useRef } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { api } from '@/api/client.js'
import { syncEmployeeCallLogsApi, syncRingCentralEmployeesApi } from '@/api/shared.api.js'

/** Réessai automatique après un `429 CMN-301` (quota RingCentral). */
const RATE_LIMIT_RETRY_DELAY = 30_000
const MAX_RATE_LIMIT_RETRIES = 2

/** `2026-10-06T10:08:10Z` → `06/10 10:08` (locale fr). */
function formatDate(value) {
  if (!value) return '—'

  const date = new Date(value)

  return Number.isNaN(date.getTime())
    ? String(value)
    : date.toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })
}

/** 45 → `0:45`, 3 725 → `1:02:05`. */
function formatDuration(seconds) {
  const total = Math.max(0, Math.floor(Number(seconds) || 0))
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = total % 60
  const pad = (n) => String(n).padStart(2, '0')

  return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${m}:${pad(s)}`
}

function errorMessage(error) {
  return (
    error?.response?.data?.error ??
    error?.response?.data?.message ??
    'Impossible de charger le journal des appels.'
  )
}

/**
 * Journal d'appels d'un employé (onglet « Appels ») :
 *
 *   * **lecture locale** — `GET /call-logs/employees/{id}/logs` lit
 *     `call_logs` : aucune API appelée à l'ouverture (ni latence, ni
 *     quota `429 CMN-301`) ;
 *   * **« Synchroniser »** — `POST …/logs/sync` récupère le journal
 *     RingCentral et l'écrit en base (le rapport `created` / `updated`
 *     est affiché ensuite) ;
 *   * **« Relier les employés »** — `POST /call-logs/sync/employees`,
 *     proposé quand la fiche n'a encore aucune extension RingCentral.
 *
 * @param {string|number} id  identifiant de l'utilisateur (fiche employé)
 */
export function useCallLogs(id) {
  const queryClient = useQueryClient()
  const queryKey = ['employe-call-logs', id]

  const query = useQuery({
    queryKey,
    queryFn: () => api.get(`/call-logs/employees/${id}/logs`),
    enabled: !!id,
    retry: false,
    staleTime: 60_000,
  })

  // Un `429 CMN-301` ne doit pas rester bloqué : on retente automatiquement
  // (2 fois max) après la fenêtre de quota RingCentral.
  const retries = useRef(0)
  const upstreamStatus =
    query.error?.response?.data?.upstream?.status ?? query.error?.response?.status ?? null
  const isRateLimited = upstreamStatus === 429

  useEffect(() => {
    if (!isRateLimited || retries.current >= MAX_RATE_LIMIT_RETRIES) return

    retries.current += 1
    const timer = setTimeout(() => query.refetch(), RATE_LIMIT_RETRY_DELAY)

    return () => clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query.error])

  // Synchronisation du journal : la réponse contient déjà le journal complet
  // → on la range directement dans le cache, sans nouvel aller-retour.
  const sync = useMutation({
    mutationFn: () => syncEmployeeCallLogsApi(id),
    onSuccess: (body) => {
      if (body?.data) queryClient.setQueryData(queryKey, body)

      const report = body?.data?.sync ?? {}
      toast.success(
        `Journal synchronisé : ${report.created ?? 0} appel(s) ajouté(s), ${
          report.updated ?? 0
        } mis à jour.`
      )
    },
    onError: (error) => toast.error(errorMessage(error)),
  })

  // Relie les employés aux postes / numéros RingCentral, puis retente la
  // synchronisation du journal (le `422` « aucune extension » disparaît).
  const syncAll = useMutation({
    mutationFn: syncRingCentralEmployeesApi,
    onSuccess: (body) => {
      const report = body?.data ?? {}
      toast.success(
        `Employés reliés : ${report.matched ?? 0}/${report.total ?? 0} poste(s) RingCentral trouvé(s).`
      )
      sync.mutate()
    },
    onError: (error) => toast.error(errorMessage(error)),
  })

  const source = query.data?.data ?? null
  const records = Array.isArray(source?.records) ? source.records : []

  const rows = records.map((record, index) => ({
    key: record.local_id ?? record.id ?? record.session_id ?? index,
    date: formatDate(record.startTime),
    direction: record.direction === 'Outbound' ? 'Sortant' : 'Entrant',
    isOutbound: record.direction === 'Outbound',
    from: record.from?.phoneNumber ?? record.from?.name ?? '—',
    to: record.to?.phoneNumber ?? record.to?.name ?? '—',
    duration: formatDuration(record.duration),
    result: record.result ?? '—',
    recordingId: record.recording?.id ?? null,
  }))

  return {
    source,
    rows,
    isLoading: query.isLoading,
    isFetching: query.isFetching,
    isError: query.isError,
    error: query.error,
    isRateLimited,
    refetch: query.refetch,
    // Synchronisation (écriture en base)
    sync: sync.mutate,
    isSyncing: sync.isPending,
    syncAll: syncAll.mutate,
    isSyncingAll: syncAll.isPending,
    // `422` = la fiche n'a encore aucune extension RingCentral.
    needsLinking: query.error?.response?.status === 422,
  }
}
