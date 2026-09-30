import { Bookmark, FileText, PhoneIncoming, PhoneMissed, PhoneOff, RotateCcw, ShieldAlert, Voicemail } from 'lucide-react'
import { toast } from 'sonner'
import { useDeleteNote } from '../useNotes.js'

/**
 * Journal unique du client (table `notes`, docs/models.puml) : chaque entrée
 * porte son `type`, sa description optionnelle, son émetteur (`sender`,
 * utilisateur ; null = `SYSTEM` pour les événements des crons) et sa date.
 */
const NOTE_TYPE_CONFIG = {
  RESERVED: {
    icon: Bookmark,
    label: 'Réservé',
    variant: 'outline',
    nodeClass: 'bg-slate-500',
  },
  YES: {
    icon: PhoneIncoming,
    label: 'Oui',
    variant: 'success',
    nodeClass: 'bg-emerald-500',
  },
  NO: {
    icon: PhoneMissed,
    label: 'Non',
    variant: 'destructive',
    nodeClass: 'bg-red-500',
  },
  BV: {
    icon: Voicemail,
    label: 'BV',
    variant: 'warning',
    nodeClass: 'bg-amber-500',
  },
  CALL_BACK: {
    icon: PhoneOff,
    label: 'À rappeler',
    variant: 'info',
    nodeClass: 'bg-blue-500',
  },
  BLACKLISTED: {
    icon: ShieldAlert,
    label: 'Liste noire',
    variant: 'default',
    nodeClass: 'bg-zinc-800',
  },
  RETURNED_TO_AVAILABLE: {
    icon: RotateCcw,
    label: 'Retour disponible',
    variant: 'success',
    nodeClass: 'bg-teal-500',
  },
  NOTE: {
    icon: FileText,
    label: 'Note',
    variant: 'secondary',
    nodeClass: 'bg-gray-400 dark:bg-gray-500',
  },
}

export function useNoteTimeline({ notes = [] }) {
  const deleteMut = useDeleteNote()

  const allItems = [...notes].sort(
    (a, b) => new Date(b.created_at) - new Date(a.created_at)
  )

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

  /** Config visuelle de l'événement (type inconnu -> repli « Note »). */
  const getItemConfig = (item) =>
    NOTE_TYPE_CONFIG[item.type] ?? NOTE_TYPE_CONFIG.NOTE

  /** Émetteur affiché : nom de l'employé, « Système » pour les crons. */
  const senderName = (item) => {
    if (item.sender) {
      return (
        `${item.sender.first_name ?? ''} ${item.sender.last_name ?? ''}`.trim() ||
        item.sender.email ||
        null
      )
    }

    return item.type === 'NOTE' ? null : 'Système'
  }

  const handleDeleteNote = (itemId) => {
    if (confirm('Supprimer cette note ?')) {
      deleteMut.mutate(itemId, {
        onSuccess: () => toast.success('Note supprimée.'),
        onError: (err) =>
          toast.error(err?.response?.data?.message || 'Erreur lors de la suppression.'),
      })
    }
  }

  return {
    allItems,
    formatRelativeDate,
    getItemConfig,
    senderName,
    handleDeleteNote,
  }
}
