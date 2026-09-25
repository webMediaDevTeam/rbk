import Badge from '@/components/ui/badge.jsx'

/**
 * Compte à rebours concis : "2 mois 3j" / "18j 04h" / "5h 30m".
 * Exposé ici (et non plus dans ProspectTable) pour être affiché sous le badge.
 */
export function formatReturnCountdown(returnedAt) {
  if (!returnedAt) return '—'
  const diff = new Date(returnedAt).getTime() - Date.now()
  if (diff <= 0) return 'Bientôt'

  const totalDays = Math.floor(diff / 86400000)
  const hours = Math.floor((diff % 86400000) / 3600000)
  const minutes = Math.floor((diff % 3600000) / 60000)

  if (totalDays >= 30) {
    const months = Math.floor(totalDays / 30)
    const days = totalDays % 30
    return `${months} mois ${days}j`
  }
  if (totalDays >= 1) {
    return `${totalDays}j ${String(hours).padStart(2, '0')}h`
  }
  return `${hours}h ${String(minutes).padStart(2, '0')}m`
}

/**
 * Statuts affichés (source unique, toutes les listes/tableaux clients) :
 *
 *  - `display_status` renvoyé par l'API (Client::displayStatus()) — un client
 *    RESERVED est qualifié par sa dernière réservation (OUI / NON / BV /
 *    INJOINABLE) ;
 *  - `status` brut en repli pour les payloads plus anciens.
 *
 * Couleur par statut (variant du composant `Badge`), **sauf** le badge
 * « Liste noire » qui reste neutre : noir en mode clair, gris en mode sombre
 * (variables `--status-badge` / `--status-badge-foreground` de
 * `styles/theme.css`, pilotées par la classe `.dark`).
 */
const STYLES = {
  AVAILABLE: { label: 'Disponible', variant: 'success' },
  RESERVED: { label: 'Réservé', variant: 'warning' },
  SUCCESS: { label: 'Confirmé', variant: 'success' },
  UNAVAILABLE_TEMP: { label: 'Non disponible', variant: 'destructive' },
  BLACKLISTED: { label: 'Liste noire', variant: 'neutral' },
  // Statuts affichés dérivés de la réservation en cours :
  IN_PROGRESS: { label: 'En cours de traitement', variant: 'warning' },
  IN_PROGRESS_RECALL: { label: 'En cours de traitement', variant: 'info' },
  // Anciens statuts conservés pour données historiques non migrées :
  VOICEMAIL: { label: 'Boîte vocale', variant: 'info' },
  INJOINABLE: { label: 'À RAPPELER', variant: 'info' },
  BLOCKED: { label: 'Bloqué', variant: 'destructive' },
  ARCHIVED: { label: 'Archivé', variant: 'outline' },
}

/** Badge « Liste noire » : la seule pastille sans couleur de statut. */
const NEUTRAL_CLASSES =
  'border-transparent bg-[var(--status-badge)] text-[var(--status-badge-foreground)]'

export default function ClientStatus({ status, isBlacklisted = false, returnedAt = null, className }) {
  const key = isBlacklisted ? 'BLACKLISTED' : status

  // Payload sans statut : on n'affiche rien plutôt que de mentir.
  if (!key) return null

  const style = STYLES[key] ?? { label: key, variant: 'outline' }
  const countdown = returnedAt ? formatReturnCountdown(returnedAt) : null

  return (
    <div className={`inline-flex flex-col items-start gap-0.5 ${className ?? ''}`}>
      {style.variant === 'neutral' ? (
        <span
          title={status || undefined}
          className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold transition-colors ${NEUTRAL_CLASSES}`}
        >
          {style.label}
        </span>
      ) : (
        <Badge variant={style.variant} title={status || undefined}>
          {style.label}
        </Badge>
      )}
      {countdown && (
        <span className="px-0.5 text-[10px] font-medium tabular-nums text-[var(--status-badge)]">
          Retour dans {countdown}
        </span>
      )}
    </div>
  )
}
