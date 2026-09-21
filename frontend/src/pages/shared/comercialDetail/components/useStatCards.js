import { CalendarCheck, Phone, PhoneIncoming, PhoneMissed } from 'lucide-react'

const formatCount = (n) => new Intl.NumberFormat('fr-FR').format(n ?? 0)

export function useStatCards({ analytics }) {
  const totalCalls = analytics?.calls ?? 0
  const reservations = analytics?.reservations ?? 0
  const groups = analytics?.groups ?? 0
  const clientsCalled = analytics?.clients_called ?? 0
  const clientsOui = analytics?.clients_oui ?? 0
  const clientsFailed = Math.max(clientsCalled - clientsOui, 0)

  // Rates are based on the number of distinct clients called (not the total calls).
  const successRate = clientsCalled > 0 ? ((clientsOui / clientsCalled) * 100).toFixed(1) : 0
  const failRate = clientsCalled > 0 ? ((clientsFailed / clientsCalled) * 100).toFixed(1) : 0

  const stats = [
    {
      label: 'Réservations',
      value: formatCount(groups),
      icon: CalendarCheck,
      iconClass: 'bg-primary/10 text-primary',
      badge: {
        variant: 'secondary',
        text: `${formatCount(reservations)} prospect${reservations > 1 ? 's' : ''}`,
      },
    },
    {
      label: 'Appels effectués',
      value: formatCount(totalCalls),
      icon: Phone,
      iconClass: 'bg-primary/10 text-primary',
      badge: {
        variant: 'secondary',
        text: `${formatCount(clientsCalled)} client${clientsCalled > 1 ? 's' : ''}`,
      },
    },
    {
      label: 'Réussites (OUI)',
      value: `${successRate}%`,
      icon: PhoneIncoming,
      iconClass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
      badge: {
        variant: 'success',
        text: `${formatCount(clientsOui)} / ${formatCount(clientsCalled)}`,
      },
    },
    {
      label: 'Échecs',
      value: `${failRate}%`,
      icon: PhoneMissed,
      iconClass: 'bg-destructive/10 text-destructive',
      badge: {
        variant: 'destructive',
        text: `${formatCount(clientsFailed)} / ${formatCount(clientsCalled)}`,
      },
    },
  ]

  return { stats }
}