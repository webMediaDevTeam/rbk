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
  // Liste courante = la plus récente (première ligne de la première page,
  // tri `created_at desc`) : c'est la seule qui garde un fond normal ; les
  // anciennes listes reçoivent le fond gris (`row-dimmed`, styles/theme.css).
  const currentGroupId = currentPage === 1 && groups.length > 0 ? groups[0].id : null
  // Colonne « Liste » : nom de l'employé + date de création (affichés à
  // partir des données, pas le texte `name` sauvegardé du groupe).
  const formatDateTime = (dateStr) =>
    (dateStr ? new Date(dateStr).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : '—')

  return {
    isLoading,
    groups,
    total,
    isDesktop,
    currentPage,
    totalPages,
    rowsPerPage,
    currentGroupId,
    handleAccueilClick,
    openGroupClick,
    openGroupStopClick,
    handlePageChange,
    handleRowsPerPageChange,
    formatDateTime,
  }
}