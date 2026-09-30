import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useDebouncedValue } from '@/hooks/use-debounced-value.js'
import { useIsDesktop } from '@/hooks/use-mobile.js'
import { listAdminClientsApi } from '@/api/commercial.api.js'
import { CLIENT_STATUS_KEYS } from '@/pages/shared/components/ProspectKpis/index.jsx'

export function useAdminClientsHistory(params = {}) {
  return useQuery({
    queryKey: ['admin-clients-history', params],
    queryFn: () => listAdminClientsApi(params),
  })
}

export function useClientsHistoryPage() {
  const navigate = useNavigate()
  const isDesktop = useIsDesktop()
  const [search, setSearch] = useState('')
  // Sélection **unique** des **badges de la colonne « Statut »** (valeurs
  // affichées, §9) : `null` = « Tous » (aucun filtre), sinon une seule valeur
  // active à la fois — Disponible / Blacklist filtrent le statut **client**,
  // Oui / Non / BV / À rappeler le statut de la **réservation courante**.
  // Les deux paramètres serveur sont distincts et combinés en `OR`.
  const [statusFilter, setStatusFilter] = useState(null)
  const [municipality, setMunicipality] = useState('')
  const [categories, setCategories] = useState('')
  const [region, setRegion] = useState('')
  const [currentPage, setCurrentPage] = useState(1)
  const [rowsPerPage, setRowsPerPage] = useState(50)
  const [sortBy, setSortBy] = useState('created_at')
  const [sortOrder, setSortOrder] = useState('desc')

  const debouncedSearch = useDebouncedValue(search, 400)
  const searchParam = debouncedSearch.trim().length >= 3 ? debouncedSearch.trim() : undefined

  // Ventilation du badge sélectionné entre les deux dimensions (un seul à
  // la fois : la barre est en sélection unique).
  const clientStatuses = statusFilter && CLIENT_STATUS_KEYS.includes(statusFilter) ? [statusFilter] : []
  const reservationStatuses = statusFilter && !CLIENT_STATUS_KEYS.includes(statusFilter) ? [statusFilter] : []
  const statusFilters = statusFilter ? [statusFilter] : []

  const { data, isLoading } = useAdminClientsHistory({
    search: searchParam,
    status: clientStatuses.length > 0 ? clientStatuses.join(',') : undefined,
    reservation_status: reservationStatuses.length > 0 ? reservationStatuses.join(',') : undefined,
    municipality: municipality || undefined,
    category: categories || undefined,
    administrative_region: region || undefined,
    page: currentPage,
    per_page: rowsPerPage,
    sort_by: sortBy,
    sort_order: sortOrder,
  })

  const clients = data?.data?.clients ?? []
  const total = data?.data?.pagination?.total ?? 0
  const totalPages = Math.max(1, Math.ceil(total / rowsPerPage))

  const handleSort = (column) => {
    if (sortBy === column) {
      setSortOrder((prev) => (prev === 'asc' ? 'desc' : 'asc'))
    } else {
      setSortBy(column)
      setSortOrder('asc')
    }
  }

  const handleViewDetail = (c) => navigate(`/prospects/${c.id}`)

  const handleSearchChange = (v) => {
    setSearch(v)
    setCurrentPage(1)
  }

  // Badges en **sélection unique** : cliquer un badge le rend seul actif,
  // un second clic dessus repasse à « Tous » (`null`), et « Tous » retire
  // l'unique filtre actif. Revient toujours à la 1re page.
  const handleStatusToggle = (value) => {
    setStatusFilter((prev) => (value === null || prev === value ? null : value))
    setCurrentPage(1)
  }

  const handleMunicipalityChange = (value) => {
    setMunicipality(value)
    setCurrentPage(1)
  }

  const handleCategoryChange = (value) => {
    setCategories(value)
    setCurrentPage(1)
  }

  const handleRegionChange = (value) => {
    setRegion(value)
    setCurrentPage(1)
  }

  const handleRowsPerPageChange = (n) => {
    setRowsPerPage(n)
    setCurrentPage(1)
  }

  const handleHomeClick = (e) => e.preventDefault()

  return {
    isDesktop,
    isLoading,
    search,
    handleSearchChange,
    statusFilters,
    handleStatusToggle,
    municipality,
    handleMunicipalityChange,
    categories,
    handleCategoryChange,
    region,
    handleRegionChange,
    currentPage,
    setCurrentPage,
    rowsPerPage,
    handleRowsPerPageChange,
    totalPages,
    clients,
    sortBy,
    sortOrder,
    handleSort,
    handleViewDetail,
    handleHomeClick,
  }
}