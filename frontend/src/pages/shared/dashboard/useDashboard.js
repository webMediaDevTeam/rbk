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
import { MONTH_LABELS } from './filters.js'

const formatCount = (n) => new Intl.NumberFormat('fr-FR').format(n ?? 0)

export function useDashboard() {
  const { role } = useAuth()
  const navigate = useNavigate()

  // Filtre de période **global** du tableau de bord (cartes, graphique,
  // réservations, « Meilleurs employés ») : Année → Mois → Semaine.
  // Réglage en cascade : changer l'année remet le mois et la semaine à
  // « tous », changer le mois remet la semaine à « toutes ».
  const [year, setYearValue] = useState(() => new Date().getFullYear())
  const [month, setMonthValue] = useState(null)
  const [week, setWeek] = useState(null)

  const setYear = (value) => {
    setYearValue(value)
    setMonthValue(null)
    setWeek(null)
  }

  const setMonth = (value) => {
    setMonthValue(value)
    setWeek(null)
  }

  // Raccourcis « Cette semaine / Ce mois-ci / Cette année » : posent les
  // trois sélecteurs d'un coup (les réglages en cascade sont court-circuités
  // volontairement). La semaine suit le découpage serveur du mois courant :
  // jours 1–7 = semaine 1, 8–14 = semaine 2…
  const applyQuickPeriod = (preset) => {
    const now = new Date()
    const currentYear = now.getFullYear()

    if (preset === 'year') {
      setYearValue(currentYear)
      setMonthValue(null)
      setWeek(null)
      return
    }

    const currentMonth = now.getMonth() + 1
    setYearValue(currentYear)

    if (preset === 'month') {
      setMonthValue(currentMonth)
      setWeek(null)
    } else if (preset === 'week') {
      setMonthValue(currentMonth)
      setWeek(Math.ceil(now.getDate() / 7))
    }
  }

  // Bouton « Réinitialiser » : retour au filtre par défaut (année courante,
  // mois et semaine « tous »).
  const resetPeriod = () => applyQuickPeriod('year')

  const { data, isLoading, isError } = useQuery({
    queryKey: ['dashboard-stats', year, month, week],
    queryFn: () => getDashboardStatsApi({ year, month, week }),
  })

  const payload = data?.data
  const stats = payload?.stats ?? {}
  const periodStats = stats?.period ?? {}
  const blacklistedClients = stats?.blacklisted_clients ?? 0
  const callsByDay = payload?.calls_by_day ?? []
  const topCommercials = payload?.top_commercials ?? []
  const chart = payload?.chart ?? {}
  const availableYears = chart.years ?? [year]
  const isAdmin = role === 'ADMIN' || role === 'SUPER_ADMIN'

  // Sous-titre du graphique : la granularité suit le filtre (mois ou
  // semaine choisis → par jour ; année seule → par mois).
  const caption = month
    ? `${MONTH_LABELS[month - 1]} ${year}${week ? ` — semaine ${week}` : ''} — évolution par jour`
    : `Année ${year} — évolution par mois`

  // Cartes cliquables : chaque KPI mène à sa section. Côté gestionnaire, la
  // Grande liste (`/clients-historique`) reçoit le filtre de statut via
  // `?status=` (valeur affichée des badges `ProspectKpis` : OUI,
  // Blacklist…) ; côté commercial, la Grande liste (`/prospects`) n'a pas de
  // filtre de statut — seules les cartes qui ont une section sont cliquables.
  const to = (path) => () => navigate(path)

  const adminCards = [
    {
      // Note « appel » = fiche appelée via le bouton Appel (RingCentral) sur la période.
      label: 'Appels',
      value: formatCount(periodStats.calls),
      icon: Phone,
      iconClass: 'bg-primary/10 text-primary',
      onClick: to('/clients-historique'),
      title: 'Voir la Grande liste — appels de la période filtrée',
    },
    {
      label: 'OUI',
      value: formatCount(periodStats.yes),
      icon: PhoneIncoming,
      iconClass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
      onClick: to('/clients-historique?status=YES'),
      title: "Notes d'issue « Oui » sur la période — Grande liste filtrée au clic",
    },
    {
      // Échec = appels − OUI : pas de vue filtrée correspondante, carte non cliquable.
      label: 'Échecs',
      value: formatCount(periodStats.failed),
      icon: PhoneMissed,
      iconClass: 'bg-destructive/10 text-destructive',
      title: 'Appels sans issue « Oui » sur la période (appels − OUI)',
    },
    {
      label: 'Clients blacklistés',
      value: formatCount(blacklistedClients),
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
      label: 'Appels',
      value: formatCount(periodStats.calls),
      icon: Phone,
      iconClass: 'bg-primary/10 text-primary',
      onClick: to('/prospects'),
      title: 'Voir mes prospects',
    },
    {
      label: 'OUI',
      value: formatCount(periodStats.yes),
      icon: PhoneIncoming,
      iconClass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    },
    {
      label: 'Échecs',
      value: formatCount(periodStats.failed),
      icon: PhoneMissed,
      iconClass: 'bg-destructive/10 text-destructive',
      title: 'Appels sans issue « Oui » sur la période (appels − OUI)',
    },
    {
      label: 'Clients blacklistés',
      value: formatCount(blacklistedClients),
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
    caption,
    years: availableYears,
    year,
    month,
    week,
    setYear,
    setMonth,
    setWeek,
    applyQuickPeriod,
    resetPeriod,
  }
}
