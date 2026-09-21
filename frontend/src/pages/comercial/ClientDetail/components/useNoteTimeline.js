import { Phone, CheckSquare, FileText, PhoneIncoming, PhoneMissed, Voicemail, PhoneOff } from 'lucide-react'
import { toast } from 'sonner'
import { useDeleteNote } from '../useNotes.js'

const NOTE_TYPE_CONFIG = {
  CALL_LOG: {
    icon: Phone,
    label: 'Appel',
    variant: 'info',
    nodeClass: 'bg-blue-500',
  },
  TASK: {
    icon: CheckSquare,
    label: 'Tâche',
    variant: 'warning',
    nodeClass: 'bg-amber-500',
  },
  GENERAL_NOTE: {
    icon: FileText,
    label: 'Note',
    variant: 'secondary',
    nodeClass: 'bg-gray-400 dark:bg-gray-500',
  },
}

const OUTCOME_CONFIG = {
  OUI: {
    icon: PhoneIncoming,
    label: 'Oui',
    variant: 'success',
    nodeClass: 'bg-emerald-500',
  },
  NON: {
    icon: PhoneMissed,
    label: 'Non',
    variant: 'destructive',
    nodeClass: 'bg-red-500',
  },
  BOITE_VOCALE: {
    icon: Voicemail,
    label: 'Boîte vocale',
    variant: 'warning',
    nodeClass: 'bg-amber-500',
  },
  INJOINABLE: {
    icon: PhoneOff,
    label: 'Injoignable',
    variant: 'info',
    nodeClass: 'bg-blue-500',
  },
}

export function useNoteTimeline({ notes = [], outcomes = [] }) {
  const deleteMut = useDeleteNote()

  const allItems = [
    ...outcomes.map((o) => ({ ...o, _type: 'outcome' })),
    ...notes.map((n) => ({ ...n, _type: 'note' })),
  ].sort((a, b) => new Date(b.created_at) - new Date(a.created_at))

  const formatDuration = (seconds) => {
    if (!seconds) return null
    const m = Math.floor(seconds / 60)
    const s = seconds % 60
    return m > 0 ? `${m}min ${s}s` : `${s}s`
  }

  const formatRelativeDate = (dateStr) => {
    const date = new Date(dateStr)
    const now = new Date()
    const diffMs = now - date
    const diffMin = Math.floor(diffMs / 60000)
    const diffH = Math.floor(diffMs / 3600000)
    const diffD = Math.floor(diffMs / 86400000)

    if (diffMin < 1) return "À l'instant"
    if (diffMin < 60) return `Il y a ${diffMin} min`
    if (diffH < 24) return `Il y a ${diffH}h`
    if (diffD < 7) return `Il y a ${diffD}j`
    return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' })
  }

  const formatRecall = (outcome) => {
    if (outcome.outcome !== 'BOITE_VOCALE' && outcome.outcome !== 'INJOINABLE') return null
    if (!outcome.recall_amount) return null
    const units = { MINUTE: 'min', HEURE: 'h', JOUR: 'j', SEMAINE: 'sem', MOIS: 'mois' }
    return `Rappel dans ${outcome.recall_amount}${units[outcome.recall_unit] ?? ''}`
  }

  const formatDueDate = (dateStr) => (dateStr ? new Date(dateStr).toLocaleDateString('fr-FR') : null)

  const getOutcomeConfig = (item) => {
    const config = OUTCOME_CONFIG[item.outcome] || OUTCOME_CONFIG.OUI
    return { config, Icon: config.icon }
  }

  const getNoteConfig = (item) => {
    const config = NOTE_TYPE_CONFIG[item.type] || NOTE_TYPE_CONFIG.GENERAL_NOTE
    return { config, Icon: config.icon }
  }

  const handleDeleteNote = (itemId) => {
    if (confirm('Supprimer cette note ?')) {
      deleteMut.mutate(itemId, {
        onSuccess: () => toast.success('Note supprimée.'),
        onError: () => toast.error('Erreur lors de la suppression.'),
      })
    }
  }

  return {
    allItems,
    formatDuration,
    formatRelativeDate,
    formatRecall,
    formatDueDate,
    getOutcomeConfig,
    getNoteConfig,
    handleDeleteNote,
  }
}