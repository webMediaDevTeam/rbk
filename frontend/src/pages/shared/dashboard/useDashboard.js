import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  BellRing,
  CalendarCheck,
  CalendarDays,
  CalendarRange,
  ListChecks,
  Phone,
  Voicemail,
} from 'lucide-react'
import { getDashboardStatsApi } from '@/api/dashboard.api.js'
import { useAuth } from '@/context/AuthContext.jsx'
import { useSourcePreference } from '@/hooks/use-source-preference.js'
import { STATUS_PILLS } from '@/pages/shared/components/ProspectKpis/status-pills.js'
import { MONTH_LABELS } from './filters.js'

/** Métadonnées d'un badge de statut (libellé, icône, teinte) par clé serveur. */
const pill = (key) => STATUS_PILLS.find((p) => p.key === key)

const formatCount = (n) => new Intl.NumberFormat('fr-FR').format(n ?? 0)

export function useDashboard() {
  const { role } = useAuth()
  const isAdmin = role === 'ADMIN' || role === 'SUPER_ADMIN'
  // L'`OPERATOR` n'a ni la Grande liste complète ni les chiffres de gestion :
  // son tableau de bord se réduit à son travail de saisie du téléphone.
  const isOperator = role === 'OPERATOR'

  // Filtre « Source » (origine du prospect, §13) : **la même sélection** que
  // les onglets de la Grande liste, persistée dans `localStorage` — posée ici,
  // elle borne toutes les cartes du gestionnaire **et** se retrouve dans la
  // liste ouverte au clic sur une carte. Ignorée côté commercial (périmètre
  // décidé par le serveur).
  const { source, setSource } = useSourcePreference()

  // Filtre de période **global** du tableau de bord (graphique, réservations,
  // « Meilleurs employés ») : Année → Mois → Semaine. Les tuiles, elles, sont
  // des instantanés : compteurs de la Grande liste côté gestionnaire, compteurs
  // de la liste de travail côté commercial — la période ne les borne pas.
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
    queryKey: ['dashboard-stats', year, month, week, isAdmin ? source : ''],
    queryFn: () => getDashboardStatsApi({ year, month, week, source: isAdmin ? source : undefined }),
  })

  const payload = data?.data
  const stats = payload?.stats ?? {}
  // Compteurs de la liste du commercial (instantanés, même périmètre que
  // `GET clients`) : « Tous » sert de dénominateur aux parts et à la jauge.
  const workCounts = {
    tous: stats?.prospects ?? 0,
    libre: stats?.libre ?? 0,
    double: stats?.double ?? 0,
    info: stats?.info ?? 0,
    bv: stats?.bv ?? 0,
    rappel: stats?.rappel ?? 0,
  }
  // Saisies de téléphone de l'opérateur (journal des notes « Mise à jour ») et
  // reste à faire dans sa liste — instantanés, la période ne les borne pas.
  const operatorCounts = {
    today: stats?.phones_today ?? 0,
    week: stats?.phones_week ?? 0,
    month: stats?.phones_month ?? 0,
    total: stats?.phones_total ?? 0,
    pending: stats?.pending_without_phone ?? 0,
  }
  const callsByDay = payload?.calls_by_day ?? []
  const topCommercials = payload?.top_commercials ?? []
  const chart = payload?.chart ?? {}
  const availableYears = chart.years ?? [year]

  // Sous-titre du graphique : la granularité suit le filtre (mois ou
  // semaine choisis → par jour ; année seule → par mois).
  const chartCaption = month
    ? `${MONTH_LABELS[month - 1]} ${year}${week ? ` — semaine ${week}` : ''} — évolution par jour`
    : `Année ${year} — évolution par mois`

  // ── Tuiles du tableau de bord ─────────────────────────────────────────
  // Côté **gestionnaire**, il n'y a plus de tuiles de période : les chiffres
  // affichés sont exclusivement les **compteurs de la Grande liste**
  // (`<DashboardStatusCards>`, instantanés bornés par l'origine). Le filtre
  // Année / Mois / Semaine ne pilote donc plus que le graphique et « Meilleurs
  // employés ».
  //
  // Côté **commercial**, les tuiles portent le **travail en cours**, toutes en
  // **instantané** sur sa liste (même périmètre serveur que `GET prospects`) :
  // « Libre » / « Double » / « Info » mènent à sa Grande liste **pré-filtrée**
  // sur le statut — icône et teinte reprises des badges de la liste
  // (`STATUS_PILLS`), donc une couleur = un statut d'un écran à l'autre —
  // tandis que « BV » et « À rappeler » mènent aux pages de suivi.
  //
  // La `ratio` de chaque tuile est sa **part de la liste de travail** (total
  // des prospects du périmètre, « Tous »).
  const share = (value) => (workCounts.tous > 0 ? value / workCounts.tous : 0)

  // Tuile d'un statut de la liste : libellé / icône / teinte du badge, compte
  // du dashboard, destination = la liste filtrée sur ce statut.
  const listTile = (key, count, title) => {
    const meta = pill(key)
    return {
      label: meta.tileLabel ?? meta.label,
      value: formatCount(count),
      ratio: share(count),
      icon: meta.icon,
      tone: meta.tone,
      to: `/prospects?status=${key}`,
      title,
    }
  }

  const commercialCards = [
    listTile('AVAILABLE', workCounts.libre, "Prospects libres à réserver — ouvre votre liste filtrée « Libre »"),
    listTile('DOUBLE', workCounts.double, 'Prospects « Double » que vous détenez encore — ouvre votre liste filtrée « Double »'),
    listTile('INFO', workCounts.info, 'Prospects « Info » que vous détenez encore — ouvre votre liste filtrée « Info »'),
    {
      label: 'BV',
      value: formatCount(workCounts.bv),
      ratio: share(workCounts.bv),
      icon: Voicemail,
      tone: 'amber',
      to: '/auto-rappels',
      title: 'Rappels BV en attente — ouvre la page « BV »',
    },
    {
      label: 'À rappeler',
      value: formatCount(workCounts.rappel),
      ratio: share(workCounts.rappel),
      icon: BellRing,
      tone: 'indigo',
      to: '/reminders',
      title: 'Rappels « À rappeler » en attente — ouvre la page dédiée',
    },
  ]

  // ── Tuiles de l'`OPERATOR` ───────────────────────────────────────────
  // Son travail : renseigner le téléphone des « Disponibles sans téléphone ».
  // Quatre compteurs de saisies (parts de son total, donc « Total » = 100 %) et
  // la file restante, seule tuile **cliquable** — elle mène à sa Grande liste,
  // dont l'API force le périmètre (`status = AVAILABLE`, non blacklisté, sans
  // numéro) : le chiffre affiché égale bien les lignes rendues (§9).
  const operatorShare = (value) =>
    (operatorCounts.total > 0 ? value / operatorCounts.total : 0)

  const remaining = operatorCounts.total + operatorCounts.pending

  const operatorCards = [
    {
      label: "Aujourd'hui",
      value: formatCount(operatorCounts.today),
      ratio: operatorShare(operatorCounts.today),
      icon: CalendarCheck,
      tone: 'emerald',
      title: 'Numéros ajoutés ou modifiés depuis minuit',
    },
    {
      label: '7 jours',
      value: formatCount(operatorCounts.week),
      ratio: operatorShare(operatorCounts.week),
      icon: CalendarRange,
      tone: 'blue',
      title: 'Numéros ajoutés ou modifiés sur les 7 derniers jours (jour courant inclus)',
    },
    {
      label: '30 jours',
      value: formatCount(operatorCounts.month),
      ratio: operatorShare(operatorCounts.month),
      icon: CalendarDays,
      tone: 'indigo',
      title: 'Numéros ajoutés ou modifiés sur les 30 derniers jours (jour courant inclus)',
    },
    {
      label: 'Total',
      value: formatCount(operatorCounts.total),
      ratio: operatorShare(operatorCounts.total),
      icon: Phone,
      tone: 'violet',
      title: 'Tous les numéros que vous avez renseignés, depuis votre compte',
    },
    {
      label: 'À faire',
      value: formatCount(operatorCounts.pending),
      ratio: remaining > 0 ? operatorCounts.pending / remaining : 0,
      icon: ListChecks,
      tone: 'amber',
      to: '/clients-historique',
      title: 'Fiches sans téléphone encore disponibles — ouvre votre Grande liste',
    },
  ]

  const cards = isAdmin ? [] : isOperator ? operatorCards : commercialCards

  // Titre / mention du panneau de tuiles : le gestionnaire n'a que son panneau
  // « Grande liste » (instantané, piloté par la source) — pas de second
  // panneau. Le commercial a le travail en cours, l'opérateur sa saisie de
  // téléphones ; chez l'un comme l'autre, la jauge de pied porte sur la liste.
  const panel = isAdmin
    ? null
    : isOperator
      ? {
          title: 'Mes numéros renseignés',
          caption: 'Instantané — les fiches sans téléphone qui restent dans votre liste',
          meter: {
            label: 'Liste traitée',
            ratio: operatorCounts.total + operatorCounts.pending > 0
              ? operatorCounts.total / (operatorCounts.total + operatorCounts.pending)
              : 0,
          },
        }
      : {
          title: 'Votre liste de travail',
          caption: 'Instantané de votre liste — à réserver, à reprendre, à rappeler',
          meter: { label: 'Contacts libres', ratio: share(workCounts.libre) },
        }

  return {
    isLoading,
    isError,
    isAdmin,
    // L'opérateur n'a ni filtre de période ni graphique : la page s'en sert
    // pour ne rendre que son panneau de tuiles.
    isOperator,
    cards,
    panel,
    // Origine active + sa bascule, exposées au barreau de filtres
    // (`<SourceTabs>`) et aux tuiles de statut.
    source,
    setSource,
    callsByDay,
    topCommercials,
    caption: chartCaption,
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
