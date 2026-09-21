import { Trash2 } from 'lucide-react'
import Badge from '@/components/ui/badge.jsx'
import { useNoteTimeline } from './useNoteTimeline.js'

export default function NoteTimeline({ notes = [], outcomes = [], clientId, readOnly = false }) {
  const {
    allItems,
    formatDuration,
    formatRelativeDate,
    formatDueDate,
    formatRecall,
    getOutcomeConfig,
    getNoteConfig,
    handleDeleteNote,
  } = useNoteTimeline({ notes, outcomes })

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
            const { config, Icon } = getOutcomeConfig(item)

            return (
              <div key={`outcome-${item.id}`} className="relative flex gap-4 py-4">
                <div className={`relative z-10 flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-full ${config.nodeClass} text-white shadow-sm`}>
                  <Icon className="h-3.5 w-3.5" />
                </div>

                <div className="min-w-0 flex-1 pt-0.5">
                  <div className="flex items-center gap-2 mb-1">
                    <Badge variant={config.variant}>{config.label}</Badge>
                    {formatRecall(item) && (
                      <span className="text-xs text-muted-foreground">{formatRecall(item)}</span>
                    )}
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

          const { config, Icon } = getNoteConfig(item)

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
                      Échéance: {formatDueDate(item.due_date)}
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
                  {!readOnly && (
                    <button
                      onClick={() => handleDeleteNote(item.id)}
                      className="ml-auto p-1 rounded-md hover:bg-destructive/10 text-muted-foreground hover:text-destructive transition-colors"
                      aria-label="Supprimer"
                    >
                      <Trash2 className="h-3.5 w-3.5" />
                    </button>
                  )}
                </div>
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}