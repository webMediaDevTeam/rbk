const statusVariants = {
  ACTIVE: 'success',
  INACTIVE: 'destructive',
  ARCHIVED: 'secondary',
}

export function useStatusBadge(props) {
  const { status } = props
  const variant = statusVariants[status] || 'secondary'
  return { variant }
}