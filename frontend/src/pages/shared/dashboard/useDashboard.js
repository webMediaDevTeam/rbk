import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import {
  Ban,
  CalendarCheck,
  Phone,
  PhoneIncoming,
  PhoneMissed,
} from 'lucide-react'
import { getDashboardStatsApi } from '@/api/dashboard.api.js'
import { useAuth } from '@/context/AuthContext.jsx'

const formatCount = (n) => new Intl.NumberFormat('fr-FR').format(n ?? 0)

/**
 * Périodes du graphique « Évolution journalière des appels » : la valeur
 * part à l'API (`?period=`), le libellé alimente le sélecteur et `caption`
 * le sous-titre du graphique.
 */
export const CHART_PERIODS = [
  { value: 'week', label: 'Semaine', caption: '7 derniers jours' },
  { value: 'month', label: 'Mois', caption: '30 derniers jours' },
  { value: 'year', label: 'Année', caption: '12 derniers mois' },
]

export function useDashboard() {
  const { role } = useAuth()
  const navigate = useNavigate()

  // Période du graphique + année / mois sélectionnés (uniquement pertinents
  // pour `month` et `year`). Les cartes d'appels comptent toujours la journée
  // en cours (`stats.today.*`).
  const [period, setPeriod] = useState('week')
  const [year, setYear] = useState(() => new Date().getFullYear())
  const [month, setMonth] = useState(() => new Date().getMonth() + 1)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['dashboard-stats', period, year, month],
    queryFn: () => getDashboardStatsApi({ period, year, month }),
  })

  const payload = data?.data
  const stats = payload?.stats ?? {}
  const today = stats?.today ?? {}
  const callsByDay = payload?.calls_by_day ?? []
  const topCommercials = payload?.top_commercials ?? []
  const chart = payload?.chart ?? {}
  const availableYears = chart.years ?? [year]
  const isAdmin = role === 'ADMIN' || role === 'SUPER_ADMIN'

  // Cartes cliquables : chaque KPI mène à sa section. Côté gestionnaire, la
  // Grande liste (`/clients-historique`) reçoit le filtre de statut via
  // `?status=` (valeur affichée des badges `ProspectKpis` : OUI, NON,
  // Blacklist…) ; côté commercial, la Grande liste (`/prospects`) n'a pas de
  // filtre de statut — seules les cartes qui ont une section sont cliquables.
  const to = (path) => () => navigate(path)

  const adminCards = [
    {
      label: 'Tous les appels',
      value: formatCount(today.calls),
      icon: Phone,
      iconClass: 'bg-primary/10 text-primary',
      onClick: to('/clients-historique'),
      title: "Voir la Grande liste — tous les appels",
    },
    {
      label: 'Appels validés (OUI)',
      value: formatCount(today.yes),
      icon: PhoneIncoming,
      iconClass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
      onClick: to('/clients-historique?status=YES'),
      title: 'Grande liste — statut « Oui »',
    },
    {
      label: 'Appels refusés',
      value: formatCount(today.no),
      icon: PhoneMissed,
      iconClass: 'bg-destructive/10 text-destructive',
      onClick: to('/clients-historique?status=NO'),
      title: 'Grande liste — statut « Non »',
    },
    {
      label: 'Appels black-listés',
      value: formatCount(today.blacklisted),
      icon: Ban,
      iconClass: 'bg-zinc-500/10 text-zinc-600 dark:text-zinc-400',
      onClick: to('/clients-historique?status=BLACKLISTED'),
      title: 'Grande liste — statut « Blacklist »',
    },
  ]

  // Pas de vue filtrée par issue d'appel côté commercial (la Grande liste
  // commerciale ne montre que les prospects disponibles) : seules « Appels »
  // et « Réservations » mènent quelque part.
  const commercialCards = [
    {
      label: "Appels aujourd'hui",
      value: formatCount(today.calls),
      icon: Phone,
      iconClass: 'bg-primary/10 text-primary',
      onClick: to('/prospects'),
      title: 'Voir mes prospects',
    },
    {
      label: 'Appels validés (OUI)',
      value: formatCount(today.yes),
      icon: PhoneIncoming,
      iconClass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    },
    {
      label: 'Appels refusés',
      value: formatCount(today.no),
      icon: PhoneMissed,
      iconClass: 'bg-destructive/10 text-destructive',
    },
    {
      label: 'Appels black-listés',
      value: formatCount(today.blacklisted),
      icon: Ban,
      iconClass: 'bg-zinc-500/10 text-zinc-600 dark:text-zinc-400',
    },
    {
      label: 'Réservations',
      value: formatCount(stats.reservations),
      icon: CalendarCheck,
      iconClass: 'bg-primary/10 text-primary',
      badge: { variant: 'secondary', text: `${formatCount(stats.groups)} groupes` },
      onClick: to('/mes-listes'),
      title: 'Voir mes listes',
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
    period,
    setPeriod,
    year,
    setYear,
    month,
    setMonth,
    availableYears,
  }
}
