import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useDebouncedValue } from '@/hooks/use-debounced-value.js'
import { useIsDesktop } from '@/hooks/use-mobile.js'
import { useReminders } from '@/pages/comercial/ClientDetail/useOutcomes.js'

export const UNIT_LABELS = {
  MINUTE: 'min',
  HEURE: 'h',
  JOUR: 'j',
}

export function formatRecallAt(dateStr) {
  const date = new Date(dateStr)
  const now = new Date()
  const diffMs = date - now
  const absDiffMs = Math.abs(diffMs)
  const diffMin = Math.floor(absDiffMs / 60000)
  const diffH = Math.floor(absDiffMs / 3600000)
  const diffD = Math.floor(absDiffMs / 86400000)

  if (diffMs < 0) {
    if (diffMin < 60) return `En retard de ${diffMin} min`
    if (diffH < 24) return `En retard de ${diffH}h`
    return `En retard de ${diffD}j`
  }
  if (diffMin < 60) return `dans ${diffMin} min`
  if (diffH < 24) return `dans ${diffH}h`
  return `dans ${diffD}j`
}

/** Date/heure du rappel : « 3 oct. 14:30 ». */
export function formatRecallFull(dateStr) {
  return new Date(dateStr).toLocaleString('fr-FR', {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  })
}

export function useRemindersPage(type = 'CALL_BACK') {
  const navigate = useNavigate()
  const isDesktop = useIsDesktop()
  const { data, isLoading } = useReminders(type)

  // --- Filtres (côté client : la liste est déjà entièrement chargée)
  const [search, setSearch] = useState('')
  const [municipality, setMunicipality] = useState('')
  const debouncedSearch = useDebouncedValue(search, 400)
  const query = debouncedSearch.trim().toLowerCase()
  const searchActive = query.length >= 3

  // --- Tri + pagination (miroir de `useCommercialProspectList`)
  const [sortBy, setSortBy] = useState('reminder_date')
  const [sortOrder, setSortOrder] = useState('asc')
  const [currentPage, setCurrentPage] = useState(1)
  const [rowsPerPage, setRowsPerPage] = useState(50)

  const reminders = data?.data ?? []

  /** Statut de réservation attendu pour cette page (CALL_BACK / BV). */
  const expectedStatus = type === 'BV' ? 'BV_VOICEMAIL' : 'CALL_BACK'

  /**
   * La ligne est-elle « à traiter » ? Elle ne l'est plus si le rappel est
   * terminé, si le statut de la réservation a changé, ou si un suivi (note)
   * a été créé depuis le rappel : le bouton « Voir » est alors masqué.
   */
  const canView = (r) =>
    !r.done_at &&
    !r.status_changed &&
    !r.has_newer_suivi &&
    r.status === expectedStatus

  // --- Filtrage + tri
  const filtered = useMemo(() => {
    const rows = reminders.filter((r) => {
      if (municipality && (r.client_municipality ?? '') !== municipality) return false
      if (!searchActive) return true

      const haystack = [r.client_name, r.enterprise_name, r.client_email, r.client_phone, r.client_municipality]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()

      return haystack.includes(query)
    })

    const dir = sortOrder === 'asc' ? 1 : -1

    return [...rows].sort((a, b) => {
      if (sortBy === 'name') {
        return (a.client_name ?? '').localeCompare(b.client_name ?? '', 'fr') * dir
      }
      // Tri par défaut : échéance du rappel (identique à l'ordre serveur).
      return (new Date(a.recall_at).getTime() - new Date(b.recall_at).getTime()) * dir
    })
  }, [reminders, municipality, searchActive, query, sortBy, sortOrder])

  const totalPages = Math.max(1, Math.ceil(filtered.length / rowsPerPage))
  const safePage = Math.min(currentPage, totalPages)
  const startIndex = (safePage - 1) * rowsPerPage
  const rows = filtered.slice(startIndex, startIndex + rowsPerPage)

  const handleSort = (column) => {
    if (sortBy === column) {
      setSortOrder((prev) => (prev === 'asc' ? 'desc' : 'asc'))
    } else {
      setSortBy(column)
      setSortOrder('asc')
    }
    setCurrentPage(1)
  }

  const handleMunicipalityChange = (value) => {
    setMunicipality(value)
    setCurrentPage(1)
  }

  const handleRowsPerPageChange = (value) => {
    setRowsPerPage(value)
    setCurrentPage(1)
  }

  const handleAccueilClick = (e) => {
    e.preventDefault()
    navigate('/prospects')
  }

  // Voir l'historique du client : détail client ouvert sur l'onglet « Historique ».
  const handleViewHistory = (reminder) => {
    navigate(`/prospects/${reminder.client_id}?tab=history`)
  }

  return {
    // Liste
    isDesktop,
    isLoading,
    reminders,
    total: filtered.length,
    rows,
    startIndex,
    // Filtres
    search, setSearch,
    municipality, setMunicipality, handleMunicipalityChange,
    // Tri + pagination
    sortBy, sortOrder, handleSort,
    currentPage: safePage,
    setCurrentPage,
    rowsPerPage, handleRowsPerPageChange,
    totalPages,
    // Lignes
    canView,
    handleAccueilClick,
    handleViewHistory,
    formatRecallAt,
    formatRecallFull,
    UNIT_LABELS,
  }
}
