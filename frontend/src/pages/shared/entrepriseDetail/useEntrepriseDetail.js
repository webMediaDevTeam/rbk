import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useDebouncedValue } from '@/hooks/use-debounced-value.js'
import { getEntrepriseStatsApi } from '@/api/admin.api.js'
import { CLIENT_STATUS_KEYS } from '@/pages/shared/components/ProspectKpis/index.jsx'

// Même structure d'onglets que le détail d'un employé (composants partagés),
// moins l'onglet « Appels » (journal RingCentral propre à un employé).
const TABS = [
  { value: 'details', label: 'Détails entreprise' },
  { value: 'employes', label: 'Employés' },
  { value: 'historique', label: 'Historique' },
]

/**
 * `GET entreprises/{id}/stats` — analytics agrégés de l'entreprise, tableau
 * des employés et historique **commun** (tous les employés) paginé avec les
 * 10 badges de la colonne « Statut ».
 */
export function useEntrepriseStats(id, { page = 1, search, status, reservationStatus, rowsPerPage } = {}) {
  return useQuery({
    queryKey: ['entreprise-stats', id, page, search, status, reservationStatus, rowsPerPage],
    queryFn: () => getEntrepriseStatsApi(id, {
      params: {
        page,
        search,
        per_page: rowsPerPage,
        // Mêmes deux paramètres que la grande liste admin (RULES §9).
        status,
        reservation_status: reservationStatus,
      },
    }),
    enabled: !!id,
  })
}

export function useEntrepriseDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()

  const [activeTab, setActiveTab] = useState('details')
  const [page, setPage] = useState(1)
  const [rowsPerPage, setRowsPerPage] = useState(50)
  const [search, setSearch] = useState('')
  // Barre de badges (onglet Historique) : **sélection unique** —
  // `null` = « Tous », sinon une seule valeur active à la fois.
  const [statusFilter, setStatusFilter] = useState(null)

  const debouncedSearch = useDebouncedValue(search, 400)
  const searchParam = debouncedSearch.trim().length >= 3 ? debouncedSearch.trim() : undefined

  // Ventilation du badge entre les deux dimensions serveur (disjointes,
  // union OR côté requête) — un seul badge actif, donc un seul paramètre.
  const statusParam = statusFilter && CLIENT_STATUS_KEYS.includes(statusFilter) ? statusFilter : undefined
  const reservationStatusParam = statusFilter && !CLIENT_STATUS_KEYS.includes(statusFilter) ? statusFilter : undefined

  const { data: body, isLoading } = useEntrepriseStats(id, {
    page,
    search: searchParam,
    status: statusParam,
    reservationStatus: reservationStatusParam,
    rowsPerPage,
  })

  // Enveloppe du serveur : `EnterpriseController::stats()` répond
  // `{success, data: {entreprise, analytics, employees, historique}}`
  // (comme les autres endpoints « entreprises » — cf. `useEntrepriseList`,
  // `data?.data?.entreprises`). Sans ce déballage, `entreprise` reste
  // `undefined` et la page affiche « Entreprise introuvable. » même
  // quand l'API répond 200.
  const data = body?.data
  const entreprise = data?.entreprise
  const analytics = data?.analytics
  const employees = data?.employees ?? []
  const historique = data?.historique
  const clients = historique?.clients ?? []
  // Compteurs des 10 badges, sur le périmètre des appels de l'entreprise.
  const badges = historique?.badges ?? null
  const statusFilters = statusFilter ? [statusFilter] : []

  const name = entreprise?.name ?? 'Chargement…'
  const companyName = entreprise?.name ?? '—'

  // Message serveur d'un échec (`404 Entreprise introuvable.`, `403 Accès
  // non autorisé.`…) : la page l'affiche plutôt que le texte générique.
  const errorMessage = body?.success === false ? body.message ?? null : null

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
    isLoading,
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
    entreprise,
    analytics,
    employees,
    historique,
    clients,
    name,
    companyName,
    errorMessage,
    handleViewDetail,
  }
}
