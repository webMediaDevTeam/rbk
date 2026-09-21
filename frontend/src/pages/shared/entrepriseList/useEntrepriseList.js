import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useAuth } from '@/context/AuthContext.jsx'
import { useDebouncedValue } from '@/hooks/use-debounced-value.js'
import { useIsDesktop } from '@/hooks/use-mobile.js'
import {
  listEntreprisesApi,
  createEntrepriseApi,
  updateEntrepriseApi,
  deleteEntrepriseApi,
  toggleEntrepriseStatusApi,
  uploadEnterpriseLogoApi,
} from '@/api/admin.api.js'

export function useEntrepriseList(params = {}) {
  return useQuery({
    queryKey: ['entreprises', params],
    queryFn: () => listEntreprisesApi(params),
  })
}

export function useCreateEntreprise() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: createEntrepriseApi,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['entreprises'] }),
  })
}

export function useUpdateEntreprise() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, ...payload }) => updateEntrepriseApi(id, payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['entreprises'] }),
  })
}

export function useDeleteEntreprise() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: deleteEntrepriseApi,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['entreprises'] }),
  })
}

export function useToggleEntrepriseStatus() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, status }) => toggleEntrepriseStatusApi(id, status),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['entreprises'] }),
  })
}

export function useEntrepriseListPage() {
  const { canAccess } = useAuth()
  const isDesktop = useIsDesktop()
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [currentPage, setCurrentPage] = useState(1)
  const [rowsPerPage, setRowsPerPage] = useState(10)
  const [sortBy, setSortBy] = useState('created_at')
  const [sortOrder, setSortOrder] = useState('desc')
  const [avatarTarget, setAvatarTarget] = useState(null)
  const [showCreate, setShowCreate] = useState(false)
  const [editTarget, setEditTarget] = useState(null)

  // Debounced search, only sent to server once >= 3 chars
  const debouncedSearch = useDebouncedValue(search, 400)
  const searchParam = debouncedSearch.trim().length >= 3 ? debouncedSearch.trim() : undefined

  const { data, isLoading } = useEntrepriseList({
    search: searchParam,
    status: statusFilter || undefined,
    page: currentPage,
    per_page: rowsPerPage,
    sort_by: sortBy,
    sort_order: sortOrder,
  })

  const deleteMut = useDeleteEntreprise()
  const toggleMut = useToggleEntrepriseStatus()

  const entreprises = data?.data?.entreprises ?? data?.data?.utilisateurs ?? []
  const total = data?.data?.pagination?.total ?? 0
  const totalPages = Math.max(1, Math.ceil(total / rowsPerPage))
  const canCreate = canAccess('entreprises:create')
  const canDelete = canAccess('entreprises:delete')

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

  const handleDelete = (id) => deleteMut.mutate(id, { onSuccess: () => toast.success('Supprimée.') })

  const handleStatusFilterChange = (s) => {
    setStatusFilter(s)
    setCurrentPage(1)
  }

  const handleRowsPerPageChange = (n) => {
    setRowsPerPage(n)
    setCurrentPage(1)
  }

  const handleLogoUpload = (formData) => uploadEnterpriseLogoApi(avatarTarget.id, formData)

  const handleOpenCreate = () => setShowCreate(true)
  const handleCloseCreate = () => setShowCreate(false)
  const handleCloseAvatar = () => setAvatarTarget(null)
  const handleCloseEdit = () => setEditTarget(null)
  const handleHomeClick = (e) => e.preventDefault()

  return {
    isDesktop,
    isLoading,
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
    canDelete,
    entreprises,
    sortBy,
    sortOrder,
    handleSort,
    handleToggleStatus,
    handleDelete,
    avatarTarget,
    setAvatarTarget,
    handleCloseAvatar,
    handleLogoUpload,
    showCreate,
    handleOpenCreate,
    handleCloseCreate,
    editTarget,
    setEditTarget,
    handleCloseEdit,
    handleHomeClick,
  }
}