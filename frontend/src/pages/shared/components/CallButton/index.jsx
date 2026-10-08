import { useState } from 'react'
import { Loader2, Phone, X } from 'lucide-react'
import { cn } from '@/lib/utils.js'
import { useDirectCall } from '@/hooks/use-direct-call.js'

/**
 * Bouton « Appeler » d'une ligne / carte de client — envoie le numéro du
 * client (`to`) ; la source (`from`) est le numéro de l'employé connecté,
 * résolue par l'API (`POST /call-logs/my-call`).
 *
 * Sans numéro sur la ligne, le bouton n'est pas rendu. L'appel en cours est
 * signalé par le spinner (désactivation du bouton le temps de la requête).
 */
export default function CallButton({ phone, name = '', clientId, label, className }) {
  const { callClient, isCalling } = useDirectCall()
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [destination, setDestination] = useState(phone ?? '')

  if (!phone) return null

  const accessibleLabel = label ?? (name ? `Appeler ${name}` : 'Appeler')

  return (
    <>
    <button
      type="button"
      onClick={(e) => {
        e.stopPropagation()
        setDestination(phone ?? '')
        setConfirmOpen(true)
      }}
      disabled={isCalling || !clientId}
      className={cn('inline-flex items-center justify-center gap-2 rounded-lg transition-colors disabled:cursor-not-allowed disabled:opacity-60', className)}
      aria-label={`${accessibleLabel} (${phone})`}
      title={`${accessibleLabel} (${phone})`}
    >
      {isCalling ? (
        <Loader2 className={cn('h-4 w-4 animate-spin', label ? 'text-white' : 'text-emerald-600')} />
      ) : (
        <Phone className={cn('h-4 w-4', label ? 'text-white' : 'text-emerald-600')} />
      )}
      {label && <span>{label}</span>}
    </button>
      {confirmOpen && (
        <div className="fixed inset-0 z-100 flex items-center justify-center bg-black/50 p-4" onClick={(e) => { e.stopPropagation(); setConfirmOpen(false) }}>
          <div role="dialog" aria-modal="true" aria-labelledby="call-confirm-title" className="w-full max-w-md rounded-xl border border-border bg-card p-6 text-card-foreground shadow-xl" onClick={(e) => e.stopPropagation()}>
            <div className="mb-5 flex items-center justify-between gap-4">
              <h2 id="call-confirm-title" className="text-lg font-semibold">Confirmer l’appel</h2>
              <button type="button" onClick={() => setConfirmOpen(false)} className="rounded-md p-1 hover:bg-muted" aria-label="Fermer">
                <X className="h-4 w-4" />
              </button>
            </div>
            <p className="mb-4 text-sm text-muted-foreground">
              {name ? `Client : ${name}. ` : ''}L’appel utilisera le compte RingCentral et le numéro source configurés pour votre compte.
            </p>
            <label htmlFor="call-destination" className="mb-1 block text-sm font-medium">Téléphone du client</label>
            <input
              id="call-destination"
              type="tel"
              required
              value={destination}
              onChange={(e) => setDestination(e.target.value)}
              className="mb-5 h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
            />
            <div className="flex justify-end gap-2">
              <button type="button" onClick={() => setConfirmOpen(false)} className="rounded-md border border-border px-3 py-2 text-sm hover:bg-muted">Annuler</button>
              <button
                type="button"
                disabled={isCalling || !destination.trim()}
                onClick={() => {
                  setConfirmOpen(false)
                  callClient(destination.trim(), clientId)
                }}
                className="inline-flex items-center gap-2 rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60"
              >
                {isCalling && <Loader2 className="h-4 w-4 animate-spin" />}
                Appeler
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  )
}
