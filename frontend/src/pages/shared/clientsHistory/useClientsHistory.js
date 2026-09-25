import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useDebouncedValue } from '@/hooks/use-debounced-value.js'
import { useIsDesktop } from '@/hooks/use-mobile.js'
import { listAdminClientsApi } from '@/api/commercial.api.js'

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
  const [status, setStatus] = useState('')
  const [municipality, setMunicipality] = useState('')
  const [categories, setCategories] = useState('')
  const [region, setRegion] = useState('')
  const [currentPage, setCurrentPage] = useState(1)
  const [rowsPerPage, setRowsPerPage] = useState(50)
  const [sortBy, setSortBy] = useState('created_at')
  const [sortOrder, setSortOrder] = useState('desc')

  const debouncedSearch = useDebouncedValue(search, 400)
  const searchParam = debouncedSearch.trim().length >= 3 ? debouncedSearch.trim() : undefined

  const { data, isLoading } = useAdminClientsHistory({
    search: searchParam,
    status: status || undefined,
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

  const handleStatusChange = (s) => {
    setStatus(s)
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
    status,
    handleStatusChange,
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