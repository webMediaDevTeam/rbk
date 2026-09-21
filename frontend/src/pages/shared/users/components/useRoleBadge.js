import { User, Users, CreditCard } from 'lucide-react'

const roleIcons = {
  Admin: User,
  Manager: Users,
  Cashier: CreditCard,
}

export function useRoleBadge({ role }) {
  const Icon = roleIcons[role] || User

  return { Icon }
}