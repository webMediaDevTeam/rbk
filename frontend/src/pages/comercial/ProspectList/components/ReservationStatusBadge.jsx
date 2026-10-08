import Badge from '@/components/ui/badge.jsx'

/**
 * Badge du statut de réservation (valeurs du modèle, docs/models.puml).
 * CALL_BACK s'affiche « À rapp.. », DOUBLE / INFO « Double » / « Info »
 * (issues qui conservent la réservation, UDAPTE.md).
 */
export default function ReservationStatusBadge({ status }) {
  const variants = {
    PENDING: 'outline',
    YES: 'success',
    NO: 'destructive',
    BV_VOICEMAIL: 'warning',
    CALL_BACK: 'warning',
    DOUBLE: 'primary',
    INFO: 'info',
    REALIZED: 'success',
  }

  // Libellés courts : « À rapp.. » tient dans la colonne « Statut ».
  const labels = {
    PENDING: 'En attente',
    YES: 'Oui',
    NO: 'Non',
    BV_VOICEMAIL: 'BV',
    CALL_BACK: 'À rapp..',
    DOUBLE: 'Double',
    INFO: 'Info',
    REALIZED: 'Réalisé',
  }

  if (!status) return null

  return <Badge variant={variants[status] ?? 'default'}>{labels[status] ?? status}</Badge>
}
