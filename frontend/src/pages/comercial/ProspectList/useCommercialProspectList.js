import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useDebouncedValue } from '@/hooks/use-debounced-value.js'
import { isSearchActive } from '@/lib/search.js'
import { useIsDesktop } from '@/hooks/use-mobile.js'
import {
  listCommercialProspectsApi,
  getCommercialProspectApi,
  getAdminClientApi,
} from '@/api/commercial.api.js'
import { useActiveReservationsCount } from '@/pages/shared/ClientDetail/useOutcomes.js'

/**
 * Valeurs filtrables par la barre « Libre / Double / Info / num
 * Répétés » de la page (`ProspectStatusPills`) : sert à valider le filtre
 * **pré-appliqué par le tableau de bord** (`/prospects?status=AVAILABLE`).
 * Toute autre valeur est ignorée — la liste commerciale ne connaît pas les
 * autres statuts (les « Oui » / « Non » / « Blacklist » ne sont jamais des
 * lignes de cette liste).
 */
const PROSPECT_STATUS_KEYS = ['AVAILABLE', 'DOUBLE', 'INFO', 'REPETE']

export function useCommercialProspectList() {
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const isDesktop = useIsDesktop()
  const [search, setSearch] = useState('')
  const [municipality, setMunicipality] = useState('')
  const [categories, setCategories] = useState('')
  const [region, setRegion] = useState('')
  // Badge de statut actif de la barre « Tous / Libre / Double / Info » :
  // `null` = « Tous » (aucun filtre), sinon la valeur envoyée en `status`.
  // Peut être **pré-appliqué depuis le tableau de bord**
  // (`/prospects?status=AVAILABLE`, la tuile « Libre ») : la valeur d'URL est
  // lue au montage puis tenue à jour à chaque clic, comme sur la Grande liste
  // admin — un rafraîchissement conserve donc la sélection.
  const [statusFilter, setStatusFilter] = useState(() => {
    const value = searchParams.get('status')
    return value && PROSPECT_STATUS_KEYS.includes(value) ? value : null
  })
  const [currentPage, setCurrentPage] = useState(1)
  const [rowsPerPage, setRowsPerPage] = useState(50)
  const [sortBy, setSortBy] = useState('created_at')
  const [sortOrder, setSortOrder] = useState('desc')

  const debouncedSearch = useDebouncedValue(search, 400)
  // 3 caractères (texte) ou 2 pour une saisie à dominante numérique —
  // voir `isSearchActive` (lib/search.js).
  const searchParam = isSearchActive(debouncedSearch) ? debouncedSearch.trim() : undefined

  const params = {
    search: searchParam,
    municipality: municipality || undefined,
    category: categories || undefined,
    administrative_region: region || undefined,
    status: statusFilter || undefined,
    page: currentPage,
    per_page: rowsPerPage,
    sort_by: sortBy,
    sort_order: sortOrder,
  }

  const { data, isLoading } = useQuery({
    queryKey: ['commercial-prospects', params],
    queryFn: () => listCommercialProspectsApi(params),
  })

  const clients = data?.data?.clients ?? []
  const total = data?.data?.pagination?.total ?? 0
  const totalPages = Math.max(1, Math.ceil(total / rowsPerPage))
  // Compteurs de la barre : périmètre de l'employé calculé par le serveur,
  // sélection de badge **exclue** (sinon les chiffres bougeraient au clic).
  const badges = data?.data?.badges ?? null

  const handleStatusFilterChange = (value) => {
    setStatusFilter(value)
    setCurrentPage(1)
    // L'URL suit la sélection (reload = même filtre) ; `searchParams` n'est lu
    // qu'au montage, une réécriture discrète suffit.
    setSearchParams(value ? { status: value } : {}, { replace: true })
  }

  const handleSort = (column) => {
    if (sortBy === column) {
      setSortOrder((prev) => (prev === 'asc' ? 'desc' : 'asc'))
    } else {
      setSortBy(column)
      setSortOrder('asc')
    }
  }

  const [showReserve, setShowReserve] = useState(false)

  const handleViewDetail = (client) => navigate(`/prospects/${client.id}`)
  const handleMunicipalityChange = (value) => {
    setMunicipality(value)
    setCurrentPage(1)
  }
  const handleCategoriesChange = (value) => {
    setCategories(value)
    setCurrentPage(1)
  }
  const handleRegionChange = (value) => {
    setRegion(value)
    setCurrentPage(1)
  }
  const handleRowsPerPageChange = (value) => {
    setRowsPerPage(value)
    setCurrentPage(1)
  }
  const closeReserve = () => setShowReserve(false)

  // Garde « traitement en cours » : tant que l'employé a des réservations
  // encore `PENDING` (« en attente ») dans ses listes, le serveur refuse un
  // nouveau lot (409) — le bouton « Réserver » suit la même règle
  // (`can_reserve` = `pending === 0`). La modale s'ouvre **aussi** quand la
  // liste est incomplète : elle y propose le bouton « Libérer la liste »
  // (§7.1) — seul moyen de débloquer sans finir les prospects en attente.
  //
  // Tant que le compteur n'est pas chargé, `canReserve` reste `false` : on ne
  // connaît pas encore l'état des listes, le bouton ne s'active donc qu'après
  // la vérification. En cas d'erreur de l'endpoint on repasse à `true` (le
  // garde-fou réel reste le 409 serveur).
  const { data: reservationCounts, isLoading: isLoadingCounts } = useActiveReservationsCount()
  const pendingReservations = reservationCounts?.data?.pending
  const canReserve = isLoadingCounts
    ? false
    : reservationCounts
      ? Boolean(reservationCounts.data.can_reserve)
      : true
  const openReserve = () => setShowReserve(true)

  return {
    pendingReservations, canReserve, isLoadingCounts,
    isDesktop,
    search, setSearch,
    municipality, setMunicipality,
    categories, setCategories,
    region, setRegion,
    statusFilter, handleStatusFilterChange,
    handleMunicipalityChange,
    handleCategoriesChange,
    handleRegionChange,
    currentPage, setCurrentPage,
    rowsPerPage, setRowsPerPage,
    handleRowsPerPageChange,
    sortBy, sortOrder, handleSort,
    clients, isLoading,
    total, totalPages, badges,
    showReserve, openReserve, closeReserve,
    handleViewDetail,
    // Filtres + tri de la page, transmis au modal « Réserver des prospects » :
    // le lot réservé est celui que l'employé voit à l'écran — **sauf** le
    // badge de statut : un « Double » / « Info » est déjà tenu par lui, il
    // n'a jamais sa place dans un lot (le serveur l'ignore de toute façon).
    filters: Object.fromEntries(
      Object.entries(params).filter(([key]) => key !== 'status'),
    ),
  }
}

export function useCommercialProspect(id) {
  return useQuery({
    queryKey: ['commercial-prospect', id],
    queryFn: () => getCommercialProspectApi(id),
    enabled: !!id,
  })
}

export function useAdminClientDetail(id) {
  return useQuery({
    queryKey: ['admin-client', id],
    queryFn: () => getAdminClientApi(id),
    enabled: !!id,
  })
}