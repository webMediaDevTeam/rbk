import Badge from '../../../../components/ui/badge'

const statusVariants = {
  Suspended: 'destructive',
  Active: 'success',
  Invited: 'info',
}

export default function StatusBadge({ status }) {
  return (
    <Badge variant={statusVariants[status] || 'secondary'}>
      {status}
    </Badge>
  )
}