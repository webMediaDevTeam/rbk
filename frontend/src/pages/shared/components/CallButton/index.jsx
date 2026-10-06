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
export default function CallButton({ phone, name = '', className }) {
  const { callClient, isCalling } = useDirectCall()

  if (!phone) return null

  const label = name ? `Appeler ${name}` : 'Appeler'

  return (
    <button
      type="button"
      onClick={(e) => {
        e.stopPropagation()
        callClient(phone)
      }}
      disabled={isCalling}
      className={cn(
        'p-1.5 rounded-lg hover:bg-muted transition-colors disabled:opacity-60 disabled:cursor-not-allowed',
        className
      )}
      aria-label={`${label} (${phone})`}
      title={`${label} (${phone})`}
    >
      {isCalling ? (
        <Loader2 className="h-4 w-4 animate-spin text-emerald-600" />
      ) : (
        <Phone className="h-4 w-4 text-emerald-600" />
      )}
    </button>
  )
}
