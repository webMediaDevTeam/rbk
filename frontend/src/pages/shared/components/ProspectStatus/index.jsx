import ClientStatus, { formatReturnCountdown } from '@/pages/shared/components/ClientStatus/index.jsx'
import ReservationStatusBadge from '@/pages/comercial/ProspectList/components/ReservationStatusBadge.jsx'

/** Réservé, aucune issue d'appel : pas de badge, juste un tiret. */
function PendingDash({ className }) {
  return (
    <div className={`inline-flex flex-col items-start gap-0.5 ${className ?? ''}`}>
      <span className="text-muted-foreground" title="Réservation en attente : aucune issue d'appel">
        -
      </span>
    </div>
  )
}

/**
 * Colonne « Statut » — **une seule valeur par ligne**, commune à toutes les
 * listes (Grande liste — panels commercial et admin, À rappeler, BV, Mes listes,
 * historique employé) : docs/RULES.md §9.
 *
 * Règle demandée par le client :
 *
 *  1. client **blacklisté** ou **(re)libéré** (relisté après un blocage)
 *     → son **statut client** : « BlackList » / « Libre » (+ compte à
 *     rebours « Retour dans … » quand `returned_at` est renseigné) ;
 *  2. sinon → le **statut de sa réservation courante** (`reservation_status`,
 *     colonne `clients.current_reservation_id`) : « Oui » / « Non » /
 *     « Boîte vocale » / « À rappeler », et **« - »** tant que la réservation
 *     est en attente (aucune issue d'appel) ;
 *  3. sans réservation courante → repli sur le statut client, pour ne jamais
 *     afficher un vide — un client « Réservé » orphelin reste sur « - ».
 *
 * Le compte à rebours de retour suit le badge quand la ligne n'est pas un
 * statut client : un client bloqué (« Non ») garde son décompte « Retour
 * dans … ».
 */
export default function ProspectStatus({
  status,
  displayStatus,
  reservationStatus,
  isBlacklisted = false,
  returnedAt = null,
  className,
}) {
  const blacklisted = Boolean(isBlacklisted) || status === 'BLACKLISTED'

  // 1. Statut client : liste noire, ou client (re)disponible.
  if (blacklisted || status === 'AVAILABLE') {
    return (
      <ClientStatus
        status={displayStatus ?? status}
        isBlacklisted={blacklisted}
        returnedAt={returnedAt}
        className={className}
      />
    )
  }

  // 2. Statut de la réservation courante.
  if (reservationStatus) {
    if (reservationStatus === 'PENDING') return <PendingDash className={className} />

    return (
      <div className={`inline-flex flex-col items-start gap-0.5 ${className ?? ''}`}>
        <ReservationStatusBadge status={reservationStatus} />
        {returnedAt && (
          <span className="px-0.5 text-[10px] font-medium tabular-nums text-[var(--status-badge)]">
            Retour dans {formatReturnCountdown(returnedAt)}
          </span>
        )}
      </div>
    )
  }

  // 3. Repli : statut client — un client « Réservé » **sans** réservation
  //    (donnée orpheline) n'a aucune issue non plus → même rendu que
  //    `PENDING`, pour rester dans le vocabulaire de la colonne.
  if (status === 'RESERVED') return <PendingDash className={className} />

  return (
    <ClientStatus
      status={displayStatus ?? status}
      isBlacklisted={blacklisted}
      returnedAt={returnedAt}
      className={className}
    />
  )
}
