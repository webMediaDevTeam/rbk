import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { callMyNumberApi, startCallRecordingApi } from '@/api/commercial.api.js'

// L'appel est envoyé avec `record: true` : la réponse arrive souvent avant
// que la partie soit connectée (statut « Setup » → RingCentral refuse avec
// `409 TAS-102 Incorrect State`) → on retente le démarrage de l'enregistrement
// jusqu'à ce que la ligne décroche (sonnerie RingCentral ≈ 30-60 s).
const RECORD_RETRY_DELAY = 3000
const RECORD_MAX_ATTEMPTS = 30 // ≈ 90 s

// L'enregistrement démarre pour le compte des gestionnaires : un COMERCIAL
// ne consulte plus le journal ni les enregistrements d'appel (réservés
// ADMIN / SUPER_ADMIN), il ne fait que déclencher la captation.
const ADMIN_ONLY_RECORDING_HINT =
  "l'enregistrement automatique RingCentral, s'il existe, sera consulté par un administrateur"

async function retryRecording(sessionId, partyId, attempt = 1) {
  try {
    await startCallRecordingApi(sessionId, partyId)
    toast.success('Enregistrement démarré.')
    return true
  } catch (err) {
    const status = err?.response?.status

    // 404 / 410 : la session n'existe plus (appel terminé) → rien d'autre
    // à tenter côté employé.
    if (status === 404 || status === 410) {
      toast.error(`Appel terminé sans enregistrement démarré : ${ADMIN_ONLY_RECORDING_HINT}.`)
      return false
    }

    if (attempt === 1 && status === 409) {
      // Informations seulement : la boucle continue en arrière-plan.
      toast.info('La ligne sonne : l’enregistrement démarrera dès la connexion.')
    }

    if (attempt >= RECORD_MAX_ATTEMPTS) {
      toast.error(`Enregistrement non démarré : ${ADMIN_ONLY_RECORDING_HINT}.`)
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
  const queryClient = useQueryClient()
  const mutation = useMutation({
    mutationFn: (payload) => callMyNumberApi(payload),
    onSuccess: (res, payload) => {
      const data = res?.data ?? {}

      toast.success(`Appel lancé vers ${payload?.to}.`)
      queryClient.invalidateQueries({ queryKey: ['client-notes', payload?.client_id] })

      if (payload?.record === false) return

      if (data.recorded) {
        toast.success('Enregistrement démarré.')
      } else if (data.session_id && data.party_id) {
        // La partie n'était pas encore connectée : on retente côté client
        // (`…/record` est ouvert au COMERCIAL) jusqu'au décrochage.
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
    callClient: (phone, clientId) => {
      if (!phone || !clientId || mutation.isPending) return
      mutation.mutate({ to: phone, client_id: clientId, record: true })
    },
    isCalling: mutation.isPending,
  }
}
