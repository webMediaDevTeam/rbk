import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useDebouncedValue } from '@/hooks/use-debounced-value.js'
import { isSearchActive } from '@/lib/search.js'
import { useIsDesktop } from '@/hooks/use-mobile.js'
import { listAdminClientsApi, adminBlacklistClientApi, adminUnblockClientApi } from '@/api/commercial.api.js'
import { CLIENT_STATUS_KEYS, STATUS_FILTER_KEYS } from '@/pages/shared/components/ProspectKpis/status-pills.js'
import { useSourcePreference } from '@/hooks/use-source-preference.js'

export function useAdminClientsHistory(params = {}) {
  return useQuery({
    queryKey: ['admin-clients-history', params],
    queryFn: () => listAdminClientsApi(params),
  })
}

export function useClientsHistoryPage() {
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const isDesktop = useIsDesktop()
  const qc = useQueryClient()
  const [search, setSearch] = useState('')
  // Sélection **unique** des **badges de la colonne « Statut »** (valeurs
  // affichées, §9) : `null` = « Tous » (aucun filtre), sinon une seule valeur
  // active à la fois — Disponible / Blacklist / Sans téléphone filtrent le
  // statut **client**, Oui / Non / BV / À rappeler le statut de la
  // **réservation courante**.
  // Les deux paramètres serveur sont distincts et combinés en `OR`.
  // Le filtre peut être **pré-appliqué par les cartes KPI du tableau de
  // bord** (`/clients-historique?status=YES`…) : la valeur d'URL est lue au
  // montage, puis maintenue en phase avec les badges pour qu'un
  // rafraîchissement conserve la sélection.
  const [statusFilter, setStatusFilter] = useState(() => {
    const value = searchParams.get('status')
    return value && STATUS_FILTER_KEYS.includes(value) ? value : null
  })
  const [municipality, setMunicipality] = useState('')
  const [categories, setCategories] = useState('')
  const [region, setRegion] = useState('')
  // Filtres « Entreprise » / « Employé ». « Entreprise » ne **filtre plus les
  // clients** : il borne uniquement la liste déroulante « Employé » (cascade —
  // seuls les employés de cette entreprise restent choisissables). C'est la
  // sélection d'« Employé » qui filtre réellement la liste. Le choix
  // d'entreprise réinitialise l'employé. `''` = « Toutes / Tous ».
  const [enterprise, setEnterprise] = useState('')
  const [employee, setEmployee] = useState('')
  // Onglet « source » actif (origine du prospect, §13) : `''` = « Tous »
  // (aucun `?source=` envoyé). Sert aussi aux compteurs des badges de statut
  // (`<ProspectKpis source>` → `GET clients/overview?source=`).
  //
  // **Préférence partagée et persistée** (`useSourcePreference`, localStorage)
  // : le même onglet est exposé sur le tableau de bord gestionnaire, et le
  // choix survit à un rechargement — voir `hooks/use-source-preference.js`.
  const { source, setSource } = useSourcePreference()
  const [currentPage, setCurrentPage] = useState(1)
  const [rowsPerPage, setRowsPerPage] = useState(50)
  const [sortBy, setSortBy] = useState('created_at')
  const [sortOrder, setSortOrder] = useState('desc')

  const debouncedSearch = useDebouncedValue(search, 400)
  // 3 caractères (texte) ou 2 pour une saisie à dominante numérique —
  // voir `isSearchActive` (lib/search.js).
  const searchParam = isSearchActive(debouncedSearch) ? debouncedSearch.trim() : undefined

  // Ventilation du badge sélectionné entre les deux dimensions (un seul à
  // la fois : la barre est en sélection unique).
  const clientStatuses = statusFilter && CLIENT_STATUS_KEYS.includes(statusFilter) ? [statusFilter] : []
  const reservationStatuses = statusFilter && !CLIENT_STATUS_KEYS.includes(statusFilter) ? [statusFilter] : []
  const statusFilters = statusFilter ? [statusFilter] : []

  // Périmètre **partagé** entre la liste et les badges KPI : recherche,
  // facettes (municipalité / catégorie / région), onglet « source » et
  // « Employé ». Volontairement **sans « Entreprise »** : ce filtre ne porte
  // plus que la liste déroulante « Employé » (cascade entreprise → employés),
  // il ne filtre pas les clients — seul « Employé » le fait. Les badges
  // doivent compter le **même** périmètre que les lignes rendues (invariant
  // « compteur du badge = lignes », §9), hors statut / tri / pagination.
  const filterParams = {
    search: searchParam,
    municipality: municipality || undefined,
    category: categories || undefined,
    administrative_region: region || undefined,
    employee_id: employee || undefined,
    source: source || undefined,
  }

  const { data, isLoading } = useAdminClientsHistory({
    ...filterParams,
    status: clientStatuses.length > 0 ? clientStatuses.join(',') : undefined,
    reservation_status: reservationStatuses.length > 0 ? reservationStatuses.join(',') : undefined,
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

  // --- Liste noire : bascule directe depuis la ligne (Grande liste admin) --
  // **Pas de modale de confirmation** : le bouton bascule blocage /
  // déblocage en un clic (`POST commercials/clients/{id}/blacklist` pour
  // bloquer, `POST liste-noire/{id}/debloquer` pour débloquer), avec
  // spinner sur la ligne en cours et rechargement de la liste.
  const [blacklistId, setBlacklistId] = useState(null)

  const isBlocked = (c) => Boolean(c?.is_blacklisted) || c?.status === 'BLACKLISTED'

  const toggleBlacklistMutation = useMutation({
    mutationFn: (c) => (isBlocked(c) ? adminUnblockClientApi(c.id) : adminBlacklistClientApi(c.id, '')),
    onMutate: (c) => setBlacklistId(c.id),
    onSettled: () => setBlacklistId(null),
    // `_res` / `c` = appels successifs de React Query : `c` est le client
    // **avant** mutation, donc l'état qui décide du libellé du toast.
    onSuccess: (_res, c) => {
      toast.success(isBlocked(c) ? 'Client débloqué.' : 'Client mis en liste noire.')
      qc.invalidateQueries({ queryKey: ['admin-clients-history'] })
      // Badges « Disponible » / « Blacklist » / « Sans téléphone » : le
      // bascule blocage en déplace le client d'un seau à l'autre.
      qc.invalidateQueries({ queryKey: ['prospect-kpis'] })
    },
    onError: (err) => {
      toast.error(err?.response?.data?.message || 'Une erreur est survenue.')
    },
  })

  const toggleBlacklist = (c) => {
    if (blacklistId) return // une seule bascule à la fois
    toggleBlacklistMutation.mutate(c)
  }

  // --- Saisie / modification du numéro (Admin / Super Admin) --------------
  // Bouton « Modifier le numéro » en fin de ligne (et sur la carte mobile) :
  // un prospect « Sans téléphone » reprend son seau dès la saisie.
  const [phoneClient, setPhoneClient] = useState(null)
  const openPhoneEdit = (c) => setPhoneClient(c)
  const closePhoneEdit = () => setPhoneClient(null)

  const handleSearchChange = (v) => {
    setSearch(v)
    setCurrentPage(1)
  }

  // Badges en **sélection unique** : cliquer un badge le rend seul actif,
  // un second clic dessus repasse à « Tous » (`null`), et « Tous » retire
  // l'unique filtre actif. Revient toujours à la 1re page. Le paramètre
  // d'URL suit la sélection (remplacement : un clic de badge n'empile pas
  // dans l'historique de navigation).
  const handleStatusToggle = (value) => {
    const next = value === null || statusFilter === value ? null : value
    setStatusFilter(next)

    const params = new URLSearchParams(searchParams)
    if (next) params.set('status', next)
    else params.delete('status')
    setSearchParams(params, { replace: true })

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

  // Entreprise : change le périmètre des options d'employé → on réinitialise
  // l'employé sélectionné (il n'appartient peut-être plus à la nouvelle
  // entreprise) et revient à la 1re page.
  const handleEnterpriseChange = (value) => {
    setEnterprise(value)
    setEmployee('')
    setCurrentPage(1)
  }

  const handleEmployeeChange = (value) => {
    setEmployee(value)
    setCurrentPage(1)
  }

  // Onglets « source » (origine) : sélection unique — le composant bascule
  // déjà vers `''` (« Tous ») au second clic ; revient à la 1re page comme
  // tous les filtres de la liste.
  const handleSourceChange = (value) => {
    setSource(value ?? '')
    setCurrentPage(1)
  }

  const handleRowsPerPageChange = (n) => {
    setRowsPerPage(n)
    setCurrentPage(1)
  }

  // « Réinitialiser les filtres » : vide la recherche, le badge de statut,
  // les facettes (municipalité / catégorie / région) et les filtres
  // d'interaction (entreprise / employé), puis revient à la 1re page.
  //
  // L'onglet « source » est **volontairement épargné** : ce n'est pas un
  // réglage de la page mais une préférence mémorisée (`useSourcePreference`),
  // partagée avec le tableau de bord — le même choix y est aussi respecté par
  // son propre bouton « Réinitialiser ». Le tri et le nombre de lignes par page
  // restent également tels quels (ce sont des préférences d'affichage).
  const filtersActive = Boolean(
    search || statusFilter || municipality || categories || region || enterprise || employee,
  )

  const resetFilters = () => {
    setSearch('')
    setStatusFilter(null)
    setMunicipality('')
    setCategories('')
    setRegion('')
    setEnterprise('')
    setEmployee('')
    setCurrentPage(1)

    // Le badge était peut-être arrivé par l'URL (`/clients-historique?status=…`)
    // : le paramètre est retiré pour qu'un rafraîchissement ne le remette pas.
    const params = new URLSearchParams(searchParams)
    params.delete('status')
    setSearchParams(params, { replace: true })
  }

  return {
    isDesktop,
    isLoading,
    search,
    handleSearchChange,
    statusFilters,
    handleStatusToggle,
    // Périmètre courant des badges KPI (liste + facettes + source + employé) :
    // la barre `<ProspectKpis filters>` le renvoie à `GET clients/overview`
    // pour que « compteur du badge = lignes rendues » tienne **sous filtres**.
    kpFilters: filterParams,
    municipality,
    handleMunicipalityChange,
    categories,
    handleCategoryChange,
    region,
    handleRegionChange,
    enterprise,
    handleEnterpriseChange,
    employee,
    handleEmployeeChange,
    source,
    handleSourceChange,
    currentPage,
    setCurrentPage,
    // Bouton « Réinitialiser les filtres » de la barre d'outils.
    filtersActive,
    resetFilters,
    rowsPerPage,
    handleRowsPerPageChange,
    totalPages,
    clients,
    sortBy,
    sortOrder,
    handleSort,
    handleViewDetail,
    blacklistId,
    toggleBlacklist,
    phoneClient,
    openPhoneEdit,
    closePhoneEdit,
  }
}