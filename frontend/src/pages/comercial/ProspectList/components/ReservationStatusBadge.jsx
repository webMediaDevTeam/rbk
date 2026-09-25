import Badge from '@/components/ui/badge.jsx'

/**
 * Badge du statut de réservation.
 * INJOINABLE s'affiche "À RAPPELER" (UDAPTE.md).
 */
export default function ReservationStatusBadge({ status }) {
  const variants = {
    EN_ATTENT: 'outline',
    OUI: 'success',
    NON: 'destructive',
    BV: 'warning',
    INJOINABLE: 'warning',
  }

  const labels = {
    EN_ATTENT: 'En attente',
    OUI: 'Confirmé',
    NON: 'Refusé',
    BV: 'Boîte vocale',
    INJOINABLE: 'Injoignable',
  }

  if (!status) return null

  return <Badge variant={variants[status] ?? 'default'}>{labels[status] ?? status}</Badge>
}
