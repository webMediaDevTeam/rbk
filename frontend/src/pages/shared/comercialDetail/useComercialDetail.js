import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useDebouncedValue } from '@/hooks/use-debounced-value.js'
import { api } from '@/api/client.js'

const TABS = [
  { value: 'details', label: 'Détails commercial' },
  { value: 'historique', label: 'Historique' },
]

export function useComercialDetail(id, { page = 1, search } = {}) {
  return useQuery({
    queryKey: ['comercialDetail', id, page, search],
    queryFn: () => api.get(`/commercials/${id}`, { params: { page, search } }),
    enabled: !!id,
  })
}

export function useComercialDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [activeTab, setActiveTab] = useState('details')
  const [page, setPage] = useState(1)
  const [rowsPerPage, setRowsPerPage] = useState(10)
  const [search, setSearch] = useState('')

  const debouncedSearch = useDebouncedValue(search, 400)
  const searchParam = debouncedSearch.trim().length >= 3 ? debouncedSearch.trim() : undefined

  const { data } = useComercialDetail(id, { page, search: searchParam })
  const commercial = data?.user
  const employee = data?.employee
  const entreprise = data?.entreprise
  const analytics = data?.analytics
  const historique = data?.historique
  const clients = historique?.clients ?? []

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