import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useAuth } from '@/context/AuthContext.jsx'
import { useDebouncedValue } from '@/hooks/use-debounced-value.js'
import { useIsDesktop } from '@/hooks/use-mobile.js'
import {
  listCommerciauxApi,
  createCommercialApi,
  updateCommercialApi,
  deleteCommercialApi,
  toggleCommercialStatusApi,
  statistiquesCommerciauxApi,
} from '@/api/entreprise.api.js'

export function useComercialList(params = {}) {
  return useQuery({
    queryKey: ['commerciaux', params],
    queryFn: () => listCommerciauxApi(params),
  })
}

export function useComercialStats() {
  return useQuery({
    queryKey: ['commerciaux-stats'],
    queryFn: statistiquesCommerciauxApi,
  })
}

export function useCreateCommercial() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: createCommercialApi,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['commerciaux'] })
      qc.invalidateQueries({ queryKey: ['commerciaux-stats'] })
    },
  })
}

export function useUpdateCommercial() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, ...payload }) => updateCommercialApi(id, payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['commerciaux'] }),
  })
}

export function useDeleteCommercial() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: deleteCommercialApi,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['commerciaux'] })
      qc.invalidateQueries({ queryKey: ['commerciaux-stats'] })
    },
  })
}

export function useToggleCommercialStatus() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, status }) => toggleCommercialStatusApi(id, status),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['commerciaux'] }),
  })
}

export function useComercialListPage() {
  const { canAccess } = useAuth()
  const isDesktop = useIsDesktop()
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [currentPage, setCurrentPage] = useState(1)
  const [rowsPerPage, setRowsPerPage] = useState(50)
  const [sortBy, setSortBy] = useState('created_at')
  const [sortOrder, setSortOrder] = useState('desc')
  const [avatarTarget, setAvatarTarget] = useState(null)
  const [showCreate, setShowCreate] = useState(false)
  const [editTarget, setEditTarget] = useState(null)

  const debouncedSearch = useDebouncedValue(search, 400)
  const searchParam = debouncedSearch.trim().length >= 3 ? debouncedSearch.trim() : undefined

  const { data } = useComercialList({
    search: searchParam,
    status: statusFilter || undefined,
    page: currentPage,
    per_page: rowsPerPage,
    sort_by: sortBy,
    sort_order: sortOrder,
  })

  const deleteMut = useDeleteCommercial()
  const toggleMut = useToggleCommercialStatus()

  const commerciaux = data?.data?.utilisateurs ?? []
  const total = data?.data?.pagination?.total ?? 0
  const totalPages = Math.max(1, Math.ceil(total / rowsPerPage))
  const canCreate = canAccess('commercials:create')
  const canUpdate = canAccess('commercials:update')

  const handleSort = (column) => {
    if (sortBy === column) {
      setSortOrder((prev) => (prev === 'asc' ? 'desc' : 'asc'))
    } else {
      setSortBy(column)
      setSortOrder('asc')
    }
  }

  const handleToggleStatus = (id, status) =>
    toggleMut.mutate({ id, status }, { onSuccess: () => toast.success('Statut mis à jour.') })

  const handleDelete = (id) => deleteMut.mutate(id, { onSuccess: () => toast.success('Supprimé.') })

  const handleStatusFilterChange = (s) => {
    setStatusFilter(s)
    setCurrentPage(1)
  }

  const handleRowsPerPageChange = (n) => {
    setRowsPerPage(n)
    setCurrentPage(1)
  }

  const handleOpenCreate = () => setShowCreate(true)
  const handleCloseCreate = () => setShowCreate(false)
  const handleCloseAvatar = () => setAvatarTarget(null)
  const handleCloseEdit = () => setEditTarget(null)
  const handleHomeClick = (e) => e.preventDefault()

  return {
    isDesktop,
    search,
    setSearch,
    statusFilter,
    handleStatusFilterChange,
    currentPage,
    setCurrentPage,
    rowsPerPage,
    handleRowsPerPageChange,
    totalPages,
    canCreate,
    canUpdate,
    commerciaux,
    sortBy,
    sortOrder,
    handleSort,
    handleToggleStatus,
    handleDelete,
    avatarTarget,
    setAvatarTarget,
    handleCloseAvatar,
    showCreate,
    handleOpenCreate,
    handleCloseCreate,
    editTarget,
    setEditTarget,
    handleCloseEdit,
    handleHomeClick,
  }
}