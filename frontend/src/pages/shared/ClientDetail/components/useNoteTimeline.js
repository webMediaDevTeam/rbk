import { Bookmark, FileText, PhoneCall, PhoneIncoming, PhoneMissed, PhoneOff, RefreshCw, RotateCcw, ShieldAlert, Voicemail } from 'lucide-react'
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
  DOUBLE: {
    icon: PhoneOff,
    label: 'Double',
    variant: 'warning',
    nodeClass: 'bg-orange-500',
  },
  INFO: {
    icon: FileText,
    label: 'Info',
    variant: 'info',
    nodeClass: 'bg-sky-500',
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
  // Diff d'édition de fiche généré par le serveur (`ClientUpdateNote`) à
  // chaque mise à jour via la Grande liste ou la fiche commercial : la
  // description égrène les champs modifiés, ancien → nouveau valeur.
  MISE_A_JOUR: {
    icon: RefreshCw,
    label: 'Mise à jour',
    variant: 'secondary',
    nodeClass: 'bg-violet-500',
  },
  // Note « appel passé » : `type = NOTE` MAIS porte un `call_log_id` (posée
  // par le workflow RingCentral). Elle se distingue d'un simple commentaire
  // par une icône d'appel, la couleur **verte** et le libellé « Appelé ».
  CALL: {
    icon: PhoneCall,
    label: 'Appelé',
    variant: 'success',
    nodeClass: 'bg-emerald-500',
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

  /** Config visuelle de l'événement (type inconnu -> repli « Note »).
   *  Une note portant un `call_log_id` est un appel passé : elle prime sur
   *  son `type` (`NOTE`) pour afficher l'icône d'appel verte « Appelé ». */
  const getItemConfig = (item) =>
    item.call_log_id ? NOTE_TYPE_CONFIG.CALL : (NOTE_TYPE_CONFIG[item.type] ?? NOTE_TYPE_CONFIG.NOTE)

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

  /**
   * Description d'une note « Mise à jour » (`ClientUpdateNote`) découpée en
   * changements structurés pour un rendu en liste : la chaîne est produite
   * par le serveur sur le modèle `champ : « ancien » → « nouveau »`, segments
   * séparés par « · ». Les segments mal formés (description tronquée par
   * `Str::limit`) ou sans changement réel (ancien = nouveau, anciennes notes
   * d'avant correctif) sont ignorés ; un retour vide = repli texte brut.
   */
  const parseUpdateChanges = (description) => {
    if (!description) return []

    const body = description
      .replace(/^Fiche mise à jour\s*—\s*/, '')
      .replace(/\s*\.$/, '')
      .trim()

    return body
      .split(' · ')
      .map((part) => {
        const match = part.match(/^(.+?)\s*:\s*«\s*(.*?)\s*»\s*→\s*«\s*(.*?)\s*»$/)
        if (!match || match[2] === match[3]) return null
        return { label: match[1], from: match[2], to: match[3] }
      })
      .filter(Boolean)
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
    parseUpdateChanges,
    handleDeleteNote,
  }
}
