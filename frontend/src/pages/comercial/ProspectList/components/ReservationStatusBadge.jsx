import Badge from '@/components/ui/badge.jsx'

/**
 * Badge du statut de réservation (valeurs du modèle, docs/models.puml).
 * CALL_BACK s'affiche « À RAPPELER » (UDAPTE.md).
 */
export default function ReservationStatusBadge({ status }) {
  const variants = {
    PENDING: 'outline',
    YES: 'success',
    NO: 'destructive',
    BV_VOICEMAIL: 'warning',
    CALL_BACK: 'warning',
    REALIZED: 'success',
  }

  const labels = {
    PENDING: 'En attente',
    YES: 'Confirmé',
    NO: 'Refusé',
    BV_VOICEMAIL: 'Boîte vocale',
    CALL_BACK: 'À rappeler',
    REALIZED: 'Réalisé',
  }

  if (!status) return null

  return <Badge variant={variants[status] ?? 'default'}>{labels[status] ?? status}</Badge>
}
