import { Phone, CheckSquare, FileText, Trash2, PhoneIncoming, PhoneMissed, Voicemail, Ban, UserCheck } from 'lucide-react'
import Badge from '@/components/ui/badge.jsx'
import { useDeleteNote } from '../useNotes.js'
import { toast } from 'sonner'

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
  BLACKLIST: {
    icon: Ban,
    label: 'Blacklist',
    variant: 'destructive',
    nodeClass: 'bg-gray-800 dark:bg-gray-200',
  },
  UNBLACKLIST: {
    icon: UserCheck,
    label: 'Débloqué',
    variant: 'success',
    nodeClass: 'bg-emerald-600',
  },
}

function formatDuration(seconds) {
  if (!seconds) return null
  const m = Math.floor(seconds / 60)
  const s = seconds % 60
  return m > 0 ? `${m}min ${s}s` : `${s}s`
}

function formatRelativeDate(dateStr) {
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

function RecallInfo({ outcome }) {
  if (outcome.outcome !== 'BOITE_VOCALE' || !outcome.recall_amount) return null
  const units = { MINUTE: 'min', HEURE: 'h', JOUR: 'j', SEMAINE: 'sem', MOIS: 'mois' }
  return (
    <span className="text-xs text-muted-foreground">
      Rappel dans {outcome.recall_amount}{units[outcome.recall_unit] ?? ''}
    </span>
  )
}

export default function NoteTimeline({ notes = [], outcomes = [], clientId }) {
  const deleteMut = useDeleteNote()

  const allItems = [
    ...outcomes.map((o) => ({ ...o, _type: 'outcome' })),
    ...notes.map((n) => ({ ...n, _type: 'note' })),
  ].sort((a, b) => new Date(b.created_at) - new Date(a.created_at))

  if (allItems.length === 0) {
    return (
      <div className="h-48 flex items-center justify-center text-muted-foreground">
        Aucune interaction pour le moment.
      </div>
    )
  }

  return (
    <div className="relative">
      <div className="absolute left-[15px] top-0 bottom-0 w-0.5 bg-border" />

      <div className="space-y-0">
        {allItems.map((item) => {
          if (item._type === 'outcome') {
            const config = OUTCOME_CONFIG[item.outcome] || OUTCOME_CONFIG.OUI
            const Icon = config.icon

            return (
              <div key={`outcome-${item.id}`} className="relative flex gap-4 py-4">
                <div className={`relative z-10 flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-full ${config.nodeClass} text-white shadow-sm`}>
                  <Icon className="h-3.5 w-3.5" />
                </div>

                <div className="min-w-0 flex-1 pt-0.5">
                  <div className="flex items-center gap-2 mb-1">
                    <Badge variant={config.variant}>{config.label}</Badge>
                    <RecallInfo outcome={item} />
                  </div>

                  {item.note && (
                    <p className="text-sm text-foreground whitespace-pre-wrap">{item.note}</p>
                  )}

                  <div className="flex items-center gap-2 mt-2">
                    <span className="text-xs text-muted-foreground">
                      {formatRelativeDate(item.created_at)}
                    </span>
                    {item.comercial && (
                      <>
                        <span className="text-xs text-muted-foreground">·</span>
                        <span className="text-xs text-muted-foreground">
                          {item.comercial.first_name} {item.comercial.last_name}
                        </span>
                      </>
                    )}
                  </div>
                </div>
              </div>
            )
          }

          const config = NOTE_TYPE_CONFIG[item.type] || NOTE_TYPE_CONFIG.GENERAL_NOTE
          const Icon = config.icon

          return (
            <div key={`note-${item.id}`} className="relative flex gap-4 py-4">
              <div className={`relative z-10 flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-full ${config.nodeClass} text-white shadow-sm`}>
                <Icon className="h-3.5 w-3.5" />
              </div>

              <div className="min-w-0 flex-1 pt-0.5">
                <div className="flex items-center gap-2 mb-1">
                  <Badge variant={config.variant}>{config.label}</Badge>
                  {item.type === 'CALL_LOG' && item.call_duration_seconds && (
                    <span className="text-xs text-muted-foreground">
                      {formatDuration(item.call_duration_seconds)}
                    </span>
                  )}
                  {item.type === 'TASK' && item.due_date && (
                    <span className="text-xs text-muted-foreground">
                      Échéance: {new Date(item.due_date).toLocaleDateString('fr-FR')}
                    </span>
                  )}
                </div>

                <p className="text-sm text-foreground whitespace-pre-wrap">{item.content}</p>

                <div className="flex items-center gap-2 mt-2">
                  <span className="text-xs text-muted-foreground">
                    {formatRelativeDate(item.created_at)}
                  </span>
                  {item.comercial && (
                    <>
                      <span className="text-xs text-muted-foreground">·</span>
                      <span className="text-xs text-muted-foreground">
                        {item.comercial.first_name} {item.comercial.last_name}
                      </span>
                    </>
                  )}
                  <button
                    onClick={() => {
                      if (confirm('Supprimer cette note ?')) {
                        deleteMut.mutate(item.id, {
                          onSuccess: () => toast.success('Note supprimée.'),
                          onError: () => toast.error('Erreur lors de la suppression.'),
                        })
                      }
                    }}
                    className="ml-auto p-1 rounded-md hover:bg-destructive/10 text-muted-foreground hover:text-destructive transition-colors"
                    aria-label="Supprimer"
                  >
                    <Trash2 className="h-3.5 w-3.5" />
                  </button>
                </div>
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}
