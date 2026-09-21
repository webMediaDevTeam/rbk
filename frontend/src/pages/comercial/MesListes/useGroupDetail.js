import { useParams, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { getReservationGroupApi } from '@/api/commercial.api.js'
import { useIsDesktop } from '@/hooks/use-mobile.js'

export function useGroupDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const isDesktop = useIsDesktop()

  const { data, isLoading } = useQuery({
    queryKey: ['reservation-group', id],
    queryFn: () => getReservationGroupApi(id),
    enabled: !!id,
  })

  const group = data?.data?.group
  const reservations = data?.data?.reservations ?? []

  const handleMesListesClick = (e) => {
    e.preventDefault()
    navigate('/mes-listes')
  }
  const goBackClick = () => navigate(-1)
  const openProspectClick = (clientId) => () => navigate(`/prospects/${clientId}`)
  const openProspectStopClick = (clientId) => (e) => {
    e.stopPropagation()
    navigate(`/prospects/${clientId}`)
  }
  const formatDate = (dateStr) => (dateStr ? new Date(dateStr).toLocaleDateString('fr-FR') : '—')
  const formatExpiry = (dateStr) =>
    dateStr ? `Expire: ${new Date(dateStr).toLocaleDateString('fr-FR')}` : '—'

  return {
    isLoading,
    group,
    reservations,
    isDesktop,
    handleMesListesClick,
    goBackClick,
    openProspectClick,
    openProspectStopClick,
    formatDate,
    formatExpiry,
  }
}