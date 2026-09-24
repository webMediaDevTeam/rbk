import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { getReservationGroupApi, updateReservationGroupApi } from '@/api/commercial.api.js'
import { useIsDesktop } from '@/hooks/use-mobile.js'
import { toast } from 'sonner'

export function useGroupDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const isDesktop = useIsDesktop()
  const qc = useQueryClient()

  const { data, isLoading } = useQuery({
    queryKey: ['reservation-group', id],
    queryFn: () => getReservationGroupApi(id),
    enabled: !!id,
  })

  const group = data?.data?.group
  const allReservations = data?.data?.reservations ?? []

  // Masque automatiquement les prospects avec rappel planifié ("Suite appel") :
  // ils sont gérés depuis les pages « Rappels » et « Auto-rappels ».
  const reservations = allReservations.filter((r) => !r.recall_at)

  const renameMutation = useMutation({
    mutationFn: (name) => updateReservationGroupApi(id, { name }),
    onSuccess: () => {
      toast.success('Liste renommée.')
      qc.invalidateQueries({ queryKey: ['reservation-group', id] })
      qc.invalidateQueries({ queryKey: ['reservation-groups'] })
    },
    onError: (err) => {
      toast.error(err?.response?.data?.message || 'Une erreur est survenue.')
    },
  })

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

  return {
    isLoading,
    group,
    reservations,
    hiddenCount: allReservations.length - reservations.length,
    isDesktop,
    renameMutation,
    handleMesListesClick,
    goBackClick,
    openProspectClick,
    openProspectStopClick,
    formatDate,
  }
}
