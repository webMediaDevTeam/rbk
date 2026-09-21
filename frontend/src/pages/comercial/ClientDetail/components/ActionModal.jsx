import { AlertCircle, Loader2, X } from 'lucide-react'
import Button from '@/components/ui/button.jsx'
import Input from '@/components/ui/input.jsx'
import Select from '@/components/ui/select.jsx'
import { cn } from '@/lib/utils.js'
import { useActionModal } from './useActionModal.js'

export default function ActionModal({
  open,
  onClose,
  onActionSuccess,
  clientId,
  hasReservation = false,
  reservedByName = null,
}) {
  const {
    pending,
    outcome,
    note,
    error,
    recallEnabled,
    recallAmount,
    recallUnit,
    hasRecallOption,
    showRecall,
    noteRequired,
    isRecallSuggestion,
    handleSubmit,
    handleSelectOutcome,
    handleRecallEnabledChange,
    handleSelectRecallSuggestion,
    handleRecallAmountChange,
    handleRecallUnitChange,
    handleNoteChange,
    OUTCOMES,
    RECALL_UNITS,
    RECALL_SUGGESTIONS,
  } = useActionModal({ open, onClose, onActionSuccess, clientId })

  if (!open) return null

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div className="relative w-full max-w-lg max-h-[90vh] overflow-y-auto rounded-2xl bg-card p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-5">
          <h2 className="text-lg font-semibold text-foreground">Suite appel</h2>
          <button onClick={onClose} className="p-1 rounded-md hover:bg-muted" aria-label="Fermer">
            <X className="h-4 w-4" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-bold mb-1">Résultat de l'appel *</label>
            <div className="grid grid-cols-2 gap-2">
              {OUTCOMES.map((opt) => (
                <button
                  key={opt.value}
                  type="button"
                  onClick={() => handleSelectOutcome(opt.value)}
                  className={cn(
                    'px-3 py-2 text-sm rounded-lg border transition-colors text-left',
                    outcome === opt.value
                      ? 'border-primary bg-primary text-primary-foreground'
                      : 'border-border bg-background hover:bg-muted text-foreground'
                  )}
                >
                  {opt.label}
                </button>
              ))}
            </div>
            {hasReservation ? (
              <p className="text-xs text-emerald-600 dark:text-emerald-400 mt-2">
                Réservation active.
              </p>
            ) : reservedByName ? (
              <p className="text-xs text-amber-600 dark:text-amber-400 mt-2">
                Réservé par {reservedByName}. Réservation requise.
              </p>
            ) : (
              <p className="text-xs text-muted-foreground mt-2">
                Réservez ce client pour changer son statut.
              </p>
            )}
          </div>

          {hasRecallOption && outcome === 'OUI' && (
            <div className="flex items-center gap-2">
              <input
                type="checkbox"
                id="recall-toggle"
                checked={recallEnabled}
                onChange={handleRecallEnabledChange}
                className="h-4 w-4 rounded border-border accent-primary"
              />
              <label htmlFor="recall-toggle" className="text-sm font-bold cursor-pointer">
                Ajouter un rappel
              </label>
            </div>
          )}

          {showRecall && (
            <div>
              <label className="block text-sm font-bold mb-2">Rappel dans</label>
              <div className="flex flex-wrap gap-2">
                {RECALL_SUGGESTIONS.map((s) => (
                  <button
                    key={s.label}
                    type="button"
                    onClick={() => handleSelectRecallSuggestion(s)}
                    className={cn(
                      'px-3 py-1.5 text-sm rounded-lg border transition-colors',
                      isRecallSuggestion(s)
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'border-border bg-background hover:bg-muted text-foreground'
                    )}
                  >
                    {s.label}
                  </button>
                ))}
              </div>
              <label className="block text-sm font-bold mb-1 mt-3">Ou saisir un nombre</label>
              <div className="flex items-center gap-2">
                <Input
                  type="number"
                  min={1}
                  value={recallAmount}
                  onChange={handleRecallAmountChange}
                  className="w-24"
                />
                <Select value={recallUnit} onChange={handleRecallUnitChange}>
                  {RECALL_UNITS.map((u) => (
                    <option key={u.value} value={u.value}>{u.label}</option>
                  ))}
                </Select>
              </div>
            </div>
          )}

          <div>
            <label className="block text-sm font-bold mb-1">
              {noteRequired ? 'Note *' : 'Note (optionnel)'}
            </label>
            <textarea
              value={note}
              onChange={handleNoteChange}
              rows={3}
              placeholder={noteRequired ? 'Décrivez votre note...' : 'Ajoutez un commentaire...'}
              className={cn(
                'flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background',
                'placeholder:text-muted-foreground',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
              )}
            />
          </div>

          {error && (
            <div className="flex items-start gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
              <AlertCircle className="h-4 w-4 mt-0.5 shrink-0" />
              <span>{error}</span>
            </div>
          )}

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={onClose} disabled={pending}>
              Annuler
            </Button>
            <Button type="submit" disabled={pending || !hasReservation}>
              {pending && <Loader2 className="h-4 w-4 animate-spin mr-2" />}
              Confirmer
            </Button>
          </div>
        </form>
      </div>
    </div>
  )
}