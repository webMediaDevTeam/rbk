import { Loader2, Phone } from 'lucide-react'
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
export default function CallButton({ phone, name = '', label, className }) {
  const { callClient, isCalling } = useDirectCall()

  if (!phone) return null

  const accessibleLabel = label ?? (name ? `Appeler ${name}` : 'Appeler')

  return (
    <button
      type="button"
      onClick={(e) => {
        e.stopPropagation()
        callClient(phone)
      }}
      disabled={isCalling}
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
  )
}
