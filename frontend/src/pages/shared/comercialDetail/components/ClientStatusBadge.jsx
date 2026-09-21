import Badge from '@/components/ui/badge.jsx'
import { useClientStatusBadge } from './useClientStatusBadge.js'

export default function ClientStatusBadge(props) {
  const { variant, label } = useClientStatusBadge(props)
  return <Badge variant={variant}>{label}</Badge>
}