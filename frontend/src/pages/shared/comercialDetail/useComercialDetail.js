import { useState } from 'react'
import { useParams, useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useDebouncedValue } from '@/hooks/use-debounced-value.js'
import { api } from '@/api/client.js'
import { useAuth } from '@/context/AuthContext.jsx'
import { CLIENT_STATUS_KEYS, STATUS_FILTER_KEYS } from '@/pages/shared/components/ProspectKpis/status-pills.js'
import { usePeriodFilters } from '@/pages/shared/components/PeriodFilterBar/usePeriodFilters.js'

const BASE_TABS = [
  { value: 'details', label: 'Détails employé' },
  { value: 'historique', label: 'Historique' },
]

export function useComercialDetail(id, { page = 1, search, status, reservationStatus, rowsPerPage, period = {} } = {}) {
  return useQuery({
    queryKey: ['comercialDetail', id, page, search, status, reservationStatus, rowsPerPage, period],
    queryFn: () => api.get(`/commercials/${id}`, {
      params: {
        page,
        search,
        per_page: rowsPerPage,
        // Badges de la colonne « Statut » (sélection unique) : mêmes deux
        // paramètres que la grande liste admin (RULES §9 / §10).
        status,
        reservation_status: reservationStatus,
        // Période de l'historique (`year` / `month` / `week` / `day` / `range`)
        // — vide tant qu'aucune fenêtre n'est posée : la liste garde alors tout
        // l'historique (comportement d'avant le filtre).
        ...period,
      },
    }),
    enabled: !!id,
  })
}

export function useComercialDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const { role } = useAuth()
  // Onglet « Appels » (journal RingCentral + enregistrements) : réservé aux
  // mêmes rôles que les modales / routes d'appel (ADMIN, SUPER_ADMIN).
  const isAdmin = role === 'ADMIN' || role === 'SUPER_ADMIN'
  const TABS = isAdmin
    ? [...BASE_TABS, { value: 'appels', label: 'Appels' }]
    : BASE_TABS
  // Pré-filtre par l'URL — les puces « Activité » de la page « Employés »
  // mènent ici avec `?tab=historique&status=BV_VOICEMAIL|CALL_BACK` : onglet
  // et badge ouverts au montage, comme les cartes KPI du dashboard vers la
  // Grande liste. Valeur inconnue → ignorée (sélection « Tous »).
  const [activeTab, setActiveTab] = useState(() =>
    searchParams.get('tab') === 'historique' ? 'historique' : 'details',
  )
  const [page, setPage] = useState(1)
  const [rowsPerPage, setRowsPerPage] = useState(50)
  const [search, setSearch] = useState('')
  // Période de l'onglet Historique : la fenêtre borne la **date de la note
  // d'appel** de cet employé, donc la liste ET ses badges (`historique.badges`)
  // se recalculent ensemble — l'invariant §9 tient sous période. Chaque
  // réglage ramène à la 1re page, comme tous les filtres de la liste.
  const period = usePeriodFilters({ onChange: () => setPage(1) })
  // Barre de badges de statut (onglet Historique) : **sélection unique** —
  // `null` = « Tous », sinon une seule valeur active à la fois.
  const [statusFilter, setStatusFilter] = useState(() => {
    const value = searchParams.get('status')
    return value && STATUS_FILTER_KEYS.includes(value) ? value : null
  })

  const debouncedSearch = useDebouncedValue(search, 400)
  const searchParam = debouncedSearch.trim().length >= 3 ? debouncedSearch.trim() : undefined

  // Ventilation du badge entre les deux dimensions serveur (disjointes,
  // union OR côté requête) — un seul badge actif, donc un seul paramètre.
  const statusParam = statusFilter && CLIENT_STATUS_KEYS.includes(statusFilter) ? statusFilter : undefined
  const reservationStatusParam = statusFilter && !CLIENT_STATUS_KEYS.includes(statusFilter) ? statusFilter : undefined

  const { data } = useComercialDetail(id, {
    page,
    search: searchParam,
    status: statusParam,
    reservationStatus: reservationStatusParam,
    rowsPerPage,
    period: period.params,
  })
  const commercial = data?.user
  const employee = data?.employee
  const entreprise = data?.entreprise
  const analytics = data?.analytics
  const historique = data?.historique
  const clients = historique?.clients ?? []
  // Cible de la modale « Modifier » : `show()` rend `user` **à la forme des
  // lignes de la page « Employés »** (`EmployeeProfile::forCommercial()`), donc
  // `CommercialUpdateModal` consomme la fiche sans adaptation. `null` tant que
  // la requête n'a pas répondu → le bouton reste inactif.
  const updateTarget = commercial?.id ? commercial : null
  // Compteurs des 10 badges, sur le périmètre de cet employé.
  const badges = historique?.badges ?? null
  const statusFilters = statusFilter ? [statusFilter] : []
  // Années où cet employé a réellement appelé (menu « Année » de la barre de
  // période) — absentes tant que la requête n'a pas répondu : l'année courante
  // suffit pour ne pas rendre un menu vide.
  const periodYears = historique?.years ?? [new Date().getFullYear()]

  const firstName = employee?.prenom || commercial?.first_name || ''
  const lastName = employee?.nom || commercial?.last_name || ''
  const name = `${firstName} ${lastName}`.trim() || commercial?.email || 'Chargement…'
  const email = commercial?.email ?? '—'
  const phone = employee?.telephone || commercial?.phone || '—'
  const avatarUrl = employee?.image_dp_url ?? null
  const companyName = entreprise?.name ?? '—'

  const handleSearchChange = (v) => {
    setSearch(v)
    setPage(1)
  }

  const handleRowsPerPageChange = (n) => {
    setRowsPerPage(n)
    setPage(1)
  }

  const handleViewDetail = (c) => navigate(`/prospects/${c.id}`)

  // Sélection unique d'un badge de statut : le cliquer le rend seul actif,
  // un second clic dessus repasse à « Tous ». Retour à la 1re page.
  const handleStatusToggle = (value) => {
    setStatusFilter((prev) => (value === null || prev === value ? null : value))
    setPage(1)
  }

  return {
    TABS,
    id,
    activeTab,
    setActiveTab,
    page,
    setPage,
    rowsPerPage,
    handleRowsPerPageChange,
    search,
    handleSearchChange,
    statusFilters,
    handleStatusToggle,
    badges,
    period,
    periodYears,
    commercial,
    updateTarget,
    employee,
    entreprise,
    analytics,
    historique,
    clients,
    name,
    email,
    phone,
    avatarUrl,
    companyName,
    handleViewDetail,
  }
}