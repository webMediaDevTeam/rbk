import { useState } from 'react'
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

  // Filtre par **statut de réservation** : barre de badges identique à celle
  // de « Grande liste » (Tous → statuts), sélection multiple. « En
  // attente » (PENDING) est sélectionné dès l'ouverture de la page ; « Tous »
  // retire toutes les sélections.
  const [statusFilters, setStatusFilters] = useState(['PENDING'])
  const handleStatusToggle = (key) =>
    setStatusFilters((prev) =>
      key === null
        ? []
        : prev.includes(key)
          ? prev.filter((s) => s !== key)
          : [...prev, key]
    )

  const { data, isLoading } = useQuery({
    queryKey: ['reservation-group', id],
    queryFn: () => getReservationGroupApi(id),
    enabled: !!id,
  })

  const group = data?.data?.group
  const allReservations = data?.data?.reservations ?? []

  // Plus de masquage : **toutes** les réservations de la liste sont
  // affichées, y compris celles avec rappel planifié (BV / À rappeler).
  // Le rappel est montré dans la colonne « Rappel » ; le compteur
  // `rappelCount` sert d'info dans l'en-tête.
  const reservations = allReservations
  const rappelCount = allReservations.filter((r) => r.recall_at).length

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

  const goBackClick = () => navigate(-1)
  const openProspectClick = (clientId) => () => navigate(`/prospects/${clientId}`)
  const openProspectStopClick = (clientId) => (e) => {
    e.stopPropagation()
    navigate(`/prospects/${clientId}`)
  }
  const formatDate = (dateStr) => (dateStr ? new Date(dateStr).toLocaleDateString('fr-FR') : '—')

  // Lignes affichées = filtre de statut appliqué (aucun statut sélectionné =
  // tout afficher). Les compteurs des badges, eux, sont calculés sur
  // **toutes** les lignes de la liste (`GroupDetail.jsx`) : ils ne bougent
  // pas quand on sélectionne un badge (aucune ligne n'est masquée —
  // 27 lignes = badge « Tous » 27).
  const visibleReservations =
    statusFilters.length === 0
      ? reservations
      : reservations.filter((r) => statusFilters.includes(r.status))

  // Tri : les lignes **« En attente » (PENDING) passent toujours en tête**,
  // les autres statuts gardent l'ordre du serveur (created_at desc) — donc
  // Confirmé → Refusé → Boîte vocale → À rappeler. Copie (`[...]`) : on ne
  // trie jamais le tableau du cache React Query.
  const filteredReservations = [...visibleReservations].sort(
    (a, b) => (a.status === 'PENDING' ? 0 : 1) - (b.status === 'PENDING' ? 0 : 1)
  )

  return {
    isLoading,
    group,
    reservations,
    filteredReservations,
    statusFilters,
    handleStatusToggle,
    rappelCount,
    isDesktop,
    renameMutation,
    goBackClick,
    openProspectClick,
    openProspectStopClick,
    formatDate,
  }
}
