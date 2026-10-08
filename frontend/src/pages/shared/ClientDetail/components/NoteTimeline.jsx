import { Headphones, Trash2 } from 'lucide-react'
import Badge from '@/components/ui/badge.jsx'
import ClientCallDetailsModal from './ClientCallDetailsModal.jsx'
import { useNoteTimeline } from './useNoteTimeline.js'
import { useState } from 'react'

/**
 * Frise du journal d'interactions du client : un seul flux
 * (réservation, issues d'appel, listes noires, retours, commentaires).
 *
 * Seuls les commentaires (type `NOTE`) sont supprimables : les événements du
 * workflow sont immuables côté serveur.
 */
export default function NoteTimeline({ notes = [], readOnly = false }) {
  const [selectedCallId, setSelectedCallId] = useState(null)
  const {
    allItems,
    formatRelativeDate,
    getItemConfig,
    senderName,
    handleDeleteNote,
  } = useNoteTimeline({ notes })

  if (allItems.length === 0) {
    return (
      <div className="h-48 flex items-center justify-center text-muted-foreground">
        Aucune interaction pour le moment.
      </div>
    )
  }

  return (
    <div className="relative">
      <div className="absolute left-3.75 top-0 bottom-0 w-0.5 bg-border" />

      <div className="space-y-0">
        {allItems.map((item) => {
          // `getItemConfig` renvoie la config **à plat** ({icon, label,
          // variant, nodeClass}) — l'ancien contrat `{config, Icon}` du
          // refactoring a0571ed laissait `config` = undefined (crash sur
          // `config.nodeClass`).
          const { icon: Icon, ...config } = getItemConfig(item)
          const sender = senderName(item)
          const deletable = item.type === 'NOTE' && !item.call_log_id && !readOnly

          return (
            <div key={item.id} className="relative flex gap-4 py-4">
              <div className={`relative z-10 flex h-7.5 w-7.5 shrink-0 items-center justify-center rounded-full ${config.nodeClass} text-white shadow-sm`}>
                <Icon className="h-3.5 w-3.5" />
              </div>

              <div className="min-w-0 flex-1 pt-0.5">
                <div className="flex items-center gap-2 mb-1">
                  <Badge variant={config.variant}>{config.label}</Badge>
                </div>

                {item.description && (
                  <p className="text-sm text-foreground whitespace-pre-wrap">{item.description}</p>
                )}

                <div className="flex items-center gap-2 mt-2">
                  <span className="text-xs text-muted-foreground">
                    {formatRelativeDate(item.created_at)}
                  </span>
                  {sender && (
                    <>
                      <span className="text-xs text-muted-foreground">·</span>
                      <span className="text-xs text-muted-foreground">{sender}</span>
                    </>
                  )}
                  {item.call_log_id && (
                    <button
                      type="button"
                      onClick={() => setSelectedCallId(item.call_log_id)}
                      className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                    >
                      <Headphones className="h-3.5 w-3.5" /> Voir l’appel
                    </button>
                  )}
                  {deletable && (
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
      {selectedCallId && (
        <ClientCallDetailsModal callLogId={selectedCallId} onClose={() => setSelectedCallId(null)} />
      )}
    </div>
  )
}
