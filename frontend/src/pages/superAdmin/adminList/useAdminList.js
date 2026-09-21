import { useState } from 'react'
import { toast } from 'sonner'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useDebouncedValue } from '@/hooks/use-debounced-value.js'
import { useIsDesktop } from '@/hooks/use-mobile.js'
import {
  listAdminsApi,
  createAdminApi,
  updateAdminApi,
  deleteAdminApi,
  toggleAdminStatusApi,
} from '@/api/superAdmin.api.js'

export function useAdminList(params = {}) {
  return useQuery({
    queryKey: ['admins', params],
    queryFn: () => listAdminsApi(params),
  })
}

export function useCreateAdmin() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: createAdminApi,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admins'] }),
  })
}

export function useUpdateAdmin() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, ...payload }) => updateAdminApi(id, payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admins'] }),
  })
}

export function useDeleteAdmin() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: deleteAdminApi,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admins'] }),
  })
}

export function useToggleAdminStatus() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, status }) => toggleAdminStatusApi(id, status),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admins'] }),
  })
}

export function useAdminListPage() {
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

  const debouncedSearch = useDebouncedValue(search, 400)
  const searchParam = debouncedSearch.trim().length >= 3 ? debouncedSearch.trim() : undefined

  const { data } = useAdminList({
    search: searchParam,
    status: statusFilter || undefined,
    page: currentPage,
    per_page: rowsPerPage,
    sort_by: sortBy,
    sort_order: sortOrder,
  })

  const deleteMut = useDeleteAdmin()
  const toggleMut = useToggleAdminStatus()

  const admins = data?.data?.utilisateurs ?? []
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

  const handleToggleStatus = (id, status) =>
    toggleMut.mutate({ id, status }, { onSuccess: () => toast.success('Statut mis à jour.') })

  const handleDelete = (id) =>
    deleteMut.mutate(id, { onSuccess: () => toast.success('Supprimé.') })

  const handleStatusFilterChange = (s) => {
    setStatusFilter(s)
    setCurrentPage(1)
  }

  const handleRowsPerPageChange = (n) => {
    setRowsPerPage(n)
    setCurrentPage(1)
  }

  const handlePageChange = (page) => setCurrentPage(page)
  const handleOpenCreate = () => setShowCreate(true)
  const handleAvatarClick = (admin) => setAvatarTarget(admin)
  const handleEdit = (admin) => setEditTarget(admin)
  const handleCloseAvatarModal = () => setAvatarTarget(null)
  const handleCloseCreate = () => setShowCreate(false)
  const handleCloseEdit = () => setEditTarget(null)
  const handleHomeClick = (e) => e.preventDefault()

  return {
    isDesktop,
    search,
    setSearch,
    statusFilter,
    handleStatusFilterChange,
    currentPage,
    rowsPerPage,
    sortBy,
    sortOrder,
    admins,
    totalPages,
    avatarTarget,
    showCreate,
    editTarget,
    handleSort,
    handleToggleStatus,
    handleDelete,
    handleAvatarClick,
    handleEdit,
    handleOpenCreate,
    handleCloseAvatarModal,
    handleCloseCreate,
    handleCloseEdit,
    handlePageChange,
    handleRowsPerPageChange,
    handleHomeClick,
  }
}