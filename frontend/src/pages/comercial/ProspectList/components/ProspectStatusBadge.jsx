import Badge from '@/components/ui/badge.jsx'

export default function ProspectStatusBadge({ status, isBlacklisted }) {
  if (isBlacklisted) {
    return <Badge variant="destructive">Liste noire</Badge>
  }

  const variants = {
    AVAILABLE: 'success',
    RESERVED: 'warning',
    VOICEMAIL: 'info',
    INJOINABLE: 'info',
    BLOCKED: 'destructive',
    BLACKLISTED: 'destructive',
    ARCHIVED: 'outline',
  }

  const labels = {
    AVAILABLE: 'Disponible',
    RESERVED: 'Réservé',
    VOICEMAIL: 'Boîte vocale',
    INJOINABLE: 'Injoignable',
    BLOCKED: 'Bloqué',
    BLACKLISTED: 'Liste noire',
    ARCHIVED: 'Archivé',
  }

  return <Badge variant={variants[status] ?? 'default'}>{labels[status] ?? status}</Badge>
}
