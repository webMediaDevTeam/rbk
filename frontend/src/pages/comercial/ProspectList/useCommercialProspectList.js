import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { useDebouncedValue } from '@/hooks/use-debounced-value.js'
import { useIsDesktop } from '@/hooks/use-mobile.js'
import {
  listCommercialProspectsApi,
  getCommercialProspectApi,
  getAdminClientApi,
} from '@/api/commercial.api.js'

export function useCommercialProspectList() {
  const navigate = useNavigate()
  const isDesktop = useIsDesktop()
  const [search, setSearch] = useState('')
  const [categories, setCategories] = useState('')
  const [currentPage, setCurrentPage] = useState(1)
  const [rowsPerPage, setRowsPerPage] = useState(50)
  const [sortBy, setSortBy] = useState('created_at')
  const [sortOrder, setSortOrder] = useState('desc')

  const debouncedSearch = useDebouncedValue(search, 400)
  const searchParam = debouncedSearch.trim().length >= 3 ? debouncedSearch.trim() : undefined

  const params = {
    search: searchParam,
    category_id: categories || undefined,
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
  const handleCategoriesChange = (value) => {
    setCategories(value)
    setCurrentPage(1)
  }
  const handleRowsPerPageChange = (value) => {
    setRowsPerPage(value)
    setCurrentPage(1)
  }
  const openReserve = () => setShowReserve(true)
  const closeReserve = () => setShowReserve(false)

  return {
    isDesktop,
    search, setSearch,
    categories, setCategories,
    handleCategoriesChange,
    currentPage, setCurrentPage,
    rowsPerPage, setRowsPerPage,
    handleRowsPerPageChange,
    sortBy, sortOrder, handleSort,
    clients, isLoading,
    total, totalPages,
    showReserve, openReserve, closeReserve,
    handleViewDetail,
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