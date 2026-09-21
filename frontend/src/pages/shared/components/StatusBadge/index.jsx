import Badge from '@/components/ui/badge'
import { useStatusBadge } from './useStatusBadge.js'

export default function StatusBadge({ status, className }) {
  const { variant } = useStatusBadge({ status })

  return (
    <Badge variant={variant} className={className}>
      <span className="font-bold uppercase text-[11px]">{status}</span>
    </Badge>
  )
}