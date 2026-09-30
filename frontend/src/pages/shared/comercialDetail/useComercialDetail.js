import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useDebouncedValue } from '@/hooks/use-debounced-value.js'
import { api } from '@/api/client.js'
import { CLIENT_STATUS_KEYS } from '@/pages/shared/components/ProspectKpis/index.jsx'

const TABS = [
  { value: 'details', label: 'Détails employé' },
  { value: 'historique', label: 'Historique' },
]

export function useComercialDetail(id, { page = 1, search, status, reservationStatus, rowsPerPage } = {}) {
  return useQuery({
    queryKey: ['comercialDetail', id, page, search, status, reservationStatus, rowsPerPage],
    queryFn: () => api.get(`/commercials/${id}`, {
      params: {
        page,
        search,
        per_page: rowsPerPage,
        // Badges de la colonne « Statut » (sélection unique) : mêmes deux
        // paramètres que la grande liste admin (RULES §9 / §10).
        status,
        reservation_status: reservationStatus,
      },
    }),
    enabled: !!id,
  })
}

export function useComercialDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [activeTab, setActiveTab] = useState('details')
  const [page, setPage] = useState(1)
  const [rowsPerPage, setRowsPerPage] = useState(50)
  const [search, setSearch] = useState('')
  // Barre de badges de statut (onglet Historique) : **sélection unique** —
  // `null` = « Tous », sinon une seule valeur active à la fois.
  const [statusFilter, setStatusFilter] = useState(null)

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
  })
  const commercial = data?.user
  const employee = data?.employee
  const entreprise = data?.entreprise
  const analytics = data?.analytics
  const historique = data?.historique
  const clients = historique?.clients ?? []
  // Compteurs des 7 badges, sur le périmètre de cet employé.
  const badges = historique?.badges ?? null
  const statusFilters = statusFilter ? [statusFilter] : []

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
    commercial,
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