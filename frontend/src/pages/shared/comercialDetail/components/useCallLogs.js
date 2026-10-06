import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client.js'

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

/**
 * Journal d'appels RingCentral d'un employé (onglet « Appels ») :
 * `GET /call-logs/employees/{id}/logs` — extension résolue **via
 * `employees.ringcentral_device_id`** côté API, `recording` inclus.
 *
 * @param {string|number} id  identifiant de l'utilisateur (fiche employé)
 */
export function useCallLogs(id) {
  const query = useQuery({
    queryKey: ['employe-call-logs', id],
    queryFn: () => api.get(`/call-logs/employees/${id}/logs`),
    enabled: !!id,
    retry: false,
    staleTime: 60_000,
  })

  const source = query.data?.data ?? null
  const records = Array.isArray(source?.records) ? source.records : []

  const rows = records.map((record, index) => ({
    key: record.id ?? record.sessionId ?? index,
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
    refetch: query.refetch,
  }
}
