import { ArrowRight, Headphones, Trash2 } from 'lucide-react'
import Badge from '@/components/ui/badge.jsx'
import ClientCallDetailsModal from './ClientCallDetailsModal.jsx'
import { useNoteTimeline } from './useNoteTimeline.js'
import { useAuth } from '@/context/AuthContext.jsx'
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
  const { role } = useAuth()
  // « Voir l'appel » (journal + enregistrements) : réservé ADMIN /
  // SUPER_ADMIN — un COMERCIAL ne consulte plus les appels.
  const isAdmin = role === 'ADMIN' || role === 'SUPER_ADMIN'
  const {
    allItems,
    formatRelativeDate,
    getItemConfig,
    senderName,
    parseUpdateChanges,
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
          // Note « Mise à jour » : le diff serveur devient une liste
          // champ par champ (ancien barré → nouveau). Repli texte brut si
          // la description ne se parse pas.
          const updateChanges =
            item.type === 'MISE_A_JOUR' && !item.call_log_id
              ? parseUpdateChanges(item.description)
              : null

          return (
            <div key={item.id} className="relative flex gap-4 py-4">
              <div className={`relative z-10 flex h-7.5 w-7.5 shrink-0 items-center justify-center rounded-full ${config.nodeClass} text-white shadow-sm`}>
                <Icon className="h-3.5 w-3.5" />
              </div>

              <div className="min-w-0 flex-1 pt-0.5">
                <div className="flex items-center gap-2 mb-1">
                  <Badge variant={config.variant}>{config.label}</Badge>
                </div>

                {updateChanges && updateChanges.length > 0 ? (
                  <div className="mt-1 overflow-hidden rounded-lg border border-violet-200/80 bg-violet-50/60 dark:border-violet-900/60 dark:bg-violet-950/30">
                    <p className="border-b border-violet-200/80 px-3 py-1.5 text-xs font-medium text-violet-700 dark:border-violet-900/60 dark:text-violet-300">
                      Modifications de la fiche
                    </p>
                    <ul className="divide-y divide-violet-200/60 dark:divide-violet-900/40">
                      {updateChanges.map(({ label, from, to }) => (
                        <li key={`${label}-${from}-${to}`} className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 px-3 py-2 text-sm">
                          <span className="min-w-17 shrink-0 font-medium text-foreground">{label}</span>
                          <span className="text-muted-foreground line-through decoration-red-400/70">{from}</span>
                          <ArrowRight className="h-3.5 w-3.5 shrink-0 self-center text-muted-foreground" />
                          <span className="font-medium text-emerald-700 dark:text-emerald-400">{to}</span>
                        </li>
                      ))}
                    </ul>
                  </div>
                ) : item.description && (
                  <p className="text-sm text-foreground whitespace-pre-wrap">{item.description}</p>
                )}

                <div className="flex items-center gap-2 mt-2">
                  <span className="text-xs text-muted-foreground">
                    {formatRelativeDate(item.created_at)}
                  </span>
                  {sender && (
                    <>
                      <span className="text-xs text-muted-foreground">·</span>
                      {/* Émetteur de la note, préfixé « Par : » (espace
 insécable typographique française). */}
                      <span className="text-xs text-muted-foreground">Par&nbsp;: {sender}</span>
                    </>
                  )}
                  {isAdmin && item.call_log_id && (
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
