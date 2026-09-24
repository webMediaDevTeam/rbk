import Badge from '@/components/ui/badge.jsx'

export default function ProspectStatusBadge({ status, isBlacklisted }) {
  if (isBlacklisted) {
    return <Badge variant="destructive">Liste noire</Badge>
  }

  const variants = {
    AVAILABLE: 'success',
    RESERVED: 'warning',
    SUCCESS: 'success',
    UNAVAILABLE_TEMP: 'destructive',
    BLACKLISTED: 'destructive',
    // Anciens statuts conservés pour données historiques non migrées
    VOICEMAIL: 'info',
    INJOINABLE: 'info',
    BLOCKED: 'destructive',
    ARCHIVED: 'outline',
  }

  const labels = {
    AVAILABLE: 'Disponible',
    RESERVED: 'Réservé',
    SUCCESS: 'Confirmé',
    UNAVAILABLE_TEMP: 'Indisponible',
    BLACKLISTED: 'Liste noire',
    // Anciens statuts
    VOICEMAIL: 'Boîte vocale',
    INJOINABLE: 'À RAPPELER',
    BLOCKED: 'Bloqué',
    ARCHIVED: 'Archivé',
  }

  return <Badge variant={variants[status] ?? 'default'}>{labels[status] ?? status}</Badge>
}
