import Badge from '../../../components/ui/badge'

const statusVariants = {
  ACTIVE: 'success',
  INACTIVE: 'destructive',
  ARCHIVED: 'secondary',
}

export default function StatusBadge({ status, className }) {
  return (
    <Badge variant={statusVariants[status] || 'secondary'} className={className}>
      <span className="font-bold uppercase text-[11px]">{status}</span>
    </Badge>
  )
}