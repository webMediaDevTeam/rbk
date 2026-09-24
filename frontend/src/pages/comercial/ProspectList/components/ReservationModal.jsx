import { CheckCircle2, Loader2, X, AlertCircle } from 'lucide-react'
import Button from '@/components/ui/button.jsx'
import Badge from '@/components/ui/badge.jsx'
import { cn } from '@/lib/utils.js'
import { useReservationModal, COUNT_OPTIONS } from './useReservationModal.js'

export default function ReservationModal(props) {
  const {
    open,
    onClose,
    count,
    setCount,
    result,
    mutation,
    handleSubmit,
  } = useReservationModal(props)

  if (!open) return null

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div className="relative w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl bg-card p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-5">
          <h2 className="text-lg font-semibold text-foreground">Réserver des prospects</h2>
          <button onClick={onClose} className="p-1 rounded-md hover:bg-muted" aria-label="Fermer">
            <X className="h-4 w-4" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-bold mb-2">Nombre de prospects *</label>
            <div className="flex flex-wrap gap-2">
              {COUNT_OPTIONS.map((opt) => (
                <button
                  key={opt}
                  type="button"
                  onClick={() => setCount(opt)}
                  className={cn(
                    'px-3 py-1.5 text-sm rounded-lg border transition-colors',
                    count === opt
                      ? 'border-primary bg-primary text-primary-foreground'
                      : 'border-border bg-background hover:bg-muted text-foreground'
                  )}
                >
                  {opt}
                </button>
              ))}
            </div>
          </div>

          {mutation.isError && (
            <div className="flex items-start gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
              <AlertCircle className="h-4 w-4 mt-0.5 shrink-0" />
              <span>{result?.error || 'Une erreur est survenue.'}</span>
            </div>
          )}

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={onClose} disabled={mutation.isPending}>
              Annuler
            </Button>
            <Button type="submit" disabled={mutation.isPending}>
              {mutation.isPending && <Loader2 className="h-4 w-4 animate-spin mr-2" />}
              Réserver
            </Button>
          </div>
        </form>

        {result && !result.error && (
          <div className="mt-5 border-t border-border pt-5 space-y-4">
            <div className="flex items-center gap-2 text-sm font-semibold text-foreground">
              <CheckCircle2 className="h-4 w-4 text-emerald-500" />
              Résultat de la réservation
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div className="rounded-lg bg-muted/50 p-3 text-center">
                <p className="text-2xl font-bold text-foreground">{result.requested}</p>
                <p className="text-xs text-muted-foreground">Demandés</p>
              </div>
              <div className="rounded-lg bg-emerald-500/10 p-3 text-center">
                <p className="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{result.reserved}</p>
                <p className="text-xs text-muted-foreground">Réservés</p>
              </div>
            </div>

            {result.conflicts && result.conflicts.length > 0 && (
              <div>
                <p className="text-sm font-medium text-muted-foreground mb-2">
                  Conflits ({result.conflicts.length})
                </p>
                <div className="space-y-2 max-h-48 overflow-y-auto">
                  {result.conflicts.map((c) => (
                    <div key={c.client_id} className="flex items-center justify-between rounded-lg border border-border p-3 text-sm">
                      <div className="min-w-0 flex-1">
                        <p className="font-medium truncate">{c.name}</p>
                        <p className="text-xs text-muted-foreground">{c.reserved_by ? `Réservé par ${c.reserved_by}` : c.error}</p>
                      </div>
                      <div className="flex items-center gap-2 shrink-0 ml-3">
                        <Badge variant="warning">{c.reservation_status ?? c.status}</Badge>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  )
}