import { useNavigate } from 'react-router-dom'
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

export function useRemindersPage(type = 'INJOINABLE') {
  const navigate = useNavigate()
  const { data, isLoading } = useReminders(type)

  const reminders = data?.data ?? []

  const handleAccueilClick = (e) => {
    e.preventDefault()
    navigate('/prospects')
  }

  return {
    isLoading,
    reminders,
    handleAccueilClick,
    formatRecallAt,
    UNIT_LABELS,
  }
}