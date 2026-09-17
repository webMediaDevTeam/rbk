import Badge from '../../../../components/ui/badge.jsx'

export default function ClientStatusBadge({ status, isBlacklisted }) {
  if (isBlacklisted) {
    return <Badge variant="destructive">Liste noire</Badge>
  }

  const variants = {
    ACTIVE: 'success',
    INACTIVE: 'secondary',
    ARCHIVED: 'outline',
  }

  const labels = {
    ACTIVE: 'Actif',
    INACTIVE: 'Inactif',
    ARCHIVED: 'Archivé',
  }

  return <Badge variant={variants[status] ?? 'default'}>{labels[status] ?? status}</Badge>
}