import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { listReservationGroupsApi, releaseGroupPendingApi } from '@/api/commercial.api.js'
import { useIsDesktop } from '@/hooks/use-mobile.js'
import { toast } from 'sonner'

export function useMesListes() {
  const navigate = useNavigate()
  const qc = useQueryClient()
  const isDesktop = useIsDesktop()
  const [currentPage, setCurrentPage] = useState(1)
  const [rowsPerPage, setRowsPerPage] = useState(20)

  const { data, isLoading } = useQuery({
    queryKey: ['reservation-groups', currentPage, rowsPerPage],
    queryFn: () => listReservationGroupsApi({ page: currentPage, per_page: rowsPerPage }),
  })

  const groups = data?.data?.groups ?? []
  const total = data?.data?.pagination?.total ?? 0
  const totalPages = Math.max(1, Math.ceil(total / rowsPerPage))

  const releaseMutation = useMutation({
    mutationFn: (groupId) => releaseGroupPendingApi(groupId),
    onSuccess: (res, groupId) => {
      const released = res?.data?.released ?? 0
      qc.invalidateQueries({ queryKey: ['reservation-groups'] })
      qc.invalidateQueries({ queryKey: ['reservations-pending'] })
      toast.success(`${released} prospect(s) retourné(s) à disponible.`)
    },
    onError: (err) => {
      toast.error(err?.response?.data?.message || 'Une erreur est survenue.')
    },
  })

  const handleAccueilClick = (e) => e.preventDefault()
  const openGroupClick = (id) => () => navigate(`/mes-listes/${id}`)
  const openGroupStopClick = (id) => (e) => {
    e.stopPropagation()
    navigate(`/mes-listes/${id}`)
  }
  const releaseGroupClick = (id) => (e) => {
    e.stopPropagation()
    releaseMutation.mutate(id)
  }
  const handlePageChange = (page) => setCurrentPage(page)
  const handleRowsPerPageChange = (n) => {
    setRowsPerPage(n)
    setCurrentPage(1)
  }
  const formatDate = (dateStr) => (dateStr ? new Date(dateStr).toLocaleDateString('fr-FR') : '—')

  return {
    isLoading,
    groups,
    isDesktop,
    currentPage,
    totalPages,
    rowsPerPage,
    handleAccueilClick,
    openGroupClick,
    openGroupStopClick,
    releaseGroupClick,
    handlePageChange,
    handleRowsPerPageChange,
    formatDate,
  }
}