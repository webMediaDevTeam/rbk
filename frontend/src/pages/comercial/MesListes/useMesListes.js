import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { listReservationGroupsApi } from '@/api/commercial.api.js'
import { useIsDesktop } from '@/hooks/use-mobile.js'

export function useMesListes() {
  const navigate = useNavigate()
  const isDesktop = useIsDesktop()
  const [currentPage, setCurrentPage] = useState(1)
  const [rowsPerPage, setRowsPerPage] = useState(50)

  const { data, isLoading } = useQuery({
    queryKey: ['reservation-groups', currentPage, rowsPerPage],
    queryFn: () => listReservationGroupsApi({ page: currentPage, per_page: rowsPerPage }),
  })

  const groups = data?.data?.groups ?? []
  const total = data?.data?.pagination?.total ?? 0
  const totalPages = Math.max(1, Math.ceil(total / rowsPerPage))

  const handleAccueilClick = (e) => e.preventDefault()
  const openGroupClick = (id) => () => navigate(`/mes-listes/${id}`)
  const openGroupStopClick = (id) => (e) => {
    e.stopPropagation()
    navigate(`/mes-listes/${id}`)
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
    total,
    isDesktop,
    currentPage,
    totalPages,
    rowsPerPage,
    handleAccueilClick,
    openGroupClick,
    openGroupStopClick,
    handlePageChange,
    handleRowsPerPageChange,
    formatDate,
  }
}