import { User, Users, CreditCard } from 'lucide-react'

const roleIcons = {
  Admin: User,
  Manager: Users,
  Cashier: CreditCard,
}

export default function RoleBadge({ role }) {
  const Icon = roleIcons[role] || User

  return (
    <span className="inline-flex items-center gap-1.5 text-sm">
      <Icon className="h-4 w-4 text-muted-foreground" />
      {role}
    </span>
  )
}