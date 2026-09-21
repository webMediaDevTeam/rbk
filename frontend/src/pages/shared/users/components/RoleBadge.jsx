import { useRoleBadge } from './useRoleBadge.js'

export default function RoleBadge({ role }) {
  const { Icon } = useRoleBadge({ role })

  return (
    <span className="inline-flex items-center gap-1.5 text-sm">
      <Icon className="h-4 w-4 text-muted-foreground" />
      {role}
    </span>
  )
}