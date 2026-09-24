import { useQuery } from '@tanstack/react-query'
import {
  Briefcase,
  Building2,
  CalendarCheck,
  Phone,
  PhoneIncoming,
  PhoneMissed,
  Users,
} from 'lucide-react'
import { getDashboardStatsApi } from '@/api/dashboard.api.js'
import { useAuth } from '@/context/AuthContext.jsx'

export function useDashboardStats() {
  return useQuery({
    queryKey: ['dashboard-stats'],
    queryFn: getDashboardStatsApi,
  })
}

const formatCount = (n) => new Intl.NumberFormat('fr-FR').format(n ?? 0)
const rate = (part, whole) => (whole > 0 ? ((part / whole) * 100).toFixed(1) : '0.0')

export function useDashboard() {
  const { role } = useAuth()
  const { data, isLoading, isError } = useDashboardStats()

  const payload = data?.data
  const stats = payload?.stats ?? {}
  const callsByDay = payload?.calls_by_day ?? []
  const topCommercials = payload?.top_commercials ?? []
  const isAdmin = role === 'ADMIN' || role === 'SUPER_ADMIN'

  const clientsCalled = stats.clients_called ?? 0
  const clientsOui = stats.clients_oui ?? 0
  const clientsFailed = Math.max(clientsCalled - clientsOui, 0)
  const successRate = rate(clientsOui, clientsCalled)
  const failRate = rate(clientsFailed, clientsCalled)

  const adminCards = [
    {
      label: 'Entreprises',
      value: formatCount(stats.enterprises),
      icon: Building2,
      iconClass: 'bg-primary/10 text-primary',
    },
    {
      label: 'Employés',
      value: formatCount(stats.commercials),
      icon: Briefcase,
      iconClass: 'bg-primary/10 text-primary',
    },
    {
      label: 'Prospects',
      value: formatCount(stats.prospects),
      icon: Users,
      iconClass: 'bg-primary/10 text-primary',
    },
    {
      label: 'Appels effectués',
      value: formatCount(stats.calls),
      icon: Phone,
      iconClass: 'bg-primary/10 text-primary',
      badge: { variant: 'secondary', text: `${formatCount(clientsCalled)} clients` },
    },
    {
      label: 'Réservations',
      value: formatCount(stats.reservations),
      icon: CalendarCheck,
      iconClass: 'bg-primary/10 text-primary',
      badge: { variant: 'secondary', text: `${formatCount(stats.groups)} groupes` },
    },
    {
      label: 'Réussites (OUI)',
      value: `${successRate}%`,
      icon: PhoneIncoming,
      iconClass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
      badge: { variant: 'success', text: `${formatCount(clientsOui)} / ${formatCount(clientsCalled)}` },
    },
  ]

  const commercialCards = [
    {
      label: 'Appels effectués',
      value: formatCount(stats.calls),
      icon: Phone,
      iconClass: 'bg-primary/10 text-primary',
      badge: { variant: 'secondary', text: `${formatCount(clientsCalled)} clients` },
    },
    {
      label: 'Réservations',
      value: formatCount(stats.reservations),
      icon: CalendarCheck,
      iconClass: 'bg-primary/10 text-primary',
      badge: { variant: 'secondary', text: `${formatCount(stats.groups)} groupes` },
    },
    {
      label: 'Réussites (OUI)',
      value: `${successRate}%`,
      icon: PhoneIncoming,
      iconClass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
      badge: { variant: 'success', text: `${formatCount(clientsOui)} / ${formatCount(clientsCalled)}` },
    },
    {
      label: 'Échecs',
      value: `${failRate}%`,
      icon: PhoneMissed,
      iconClass: 'bg-destructive/10 text-destructive',
      badge: { variant: 'destructive', text: `${formatCount(clientsFailed)} / ${formatCount(clientsCalled)}` },
    },
  ]

  const cards = isAdmin ? adminCards : commercialCards

  return {
    isLoading,
    isError,
    isAdmin,
    cards,
    callsByDay,
    topCommercials,
  }
}
