import { useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'
import { callMyNumberApi, startCallRecordingApi } from '@/api/commercial.api.js'

// L'appel est envoyé avec `record: true` : la réponse arrive souvent avant
// que la partie soit connectée (statut « Setup ») → on retente le
// démarrage de l'enregistrement jusqu'à succès.
const RECORD_RETRY_DELAY = 2000
const RECORD_MAX_ATTEMPTS = 8 // ≈ 16 s

async function retryRecording(sessionId, partyId, attempt = 1) {
  try {
    await startCallRecordingApi(sessionId, partyId)
    toast.success('Enregistrement démarré.')
    return true
  } catch {
    if (attempt >= RECORD_MAX_ATTEMPTS) {
      toast.error("Enregistrement non démarré : l'appel n'a peut-être pas été connecté.")
      return false
    }

    setTimeout(() => retryRecording(sessionId, partyId, attempt + 1), RECORD_RETRY_DELAY)
    return false
  }
}

/**
 * Appel direct d'un client (bouton « Appeler » des pages Mes listes,
 * Rappels et BV).
 *
 * Le navigateur n'envoie que la **destination** ; la source (`from`) est le
 * numéro de l'employé connecté, résolu par l'API
 * (`POST /call-logs/my-call` → `employees.ringcentral_from_number`), et
 * `record: true` demande l'**enregistrement** de l'appel.
 *
 * @returns {{ callClient: (phone?: string|null) => void, isCalling: boolean }}
 *   `callClient(phone)` lance l'appel ; un toast confirme (appel +
 *   enregistrement) ou explique l'échec (numéro source non configuré,
 *   RingCentral injoignable…).
 */
export function useDirectCall() {
  const mutation = useMutation({
    mutationFn: (payload) => callMyNumberApi(payload),
    onSuccess: (res, payload) => {
      const data = res?.data ?? {}

      toast.success(`Appel lancé vers ${payload?.to}.`)

      if (payload?.record === false) return

      if (data.recorded) {
        toast.success('Enregistrement démarré.')
      } else if (data.session_id && data.party_id) {
        // La partie n'était pas encore connectée : on retente côté client
        // (`…/record` est ouvert au COMERCIAL).
        retryRecording(data.session_id, data.party_id)
      }
    },
    onError: (err) => {
      const data = err?.response?.data
      // 422 → message de validation du champ `to` ; 502 → message upstream
      // (`error`), sinon message générique.
      const detail = Object.values(data?.errors ?? {}).flat()[0]
      toast.error(detail ?? data?.error ?? data?.message ?? 'Une erreur est survenue.')
    },
  })

  return {
    callClient: (phone) => {
      if (!phone || mutation.isPending) return
      mutation.mutate({ to: phone, record: true })
    },
    isCalling: mutation.isPending,
  }
}
