import { useState, useEffect } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AlertCircle, CheckCircle2, Loader2, X } from 'lucide-react'
import { toast } from 'sonner'
import { reserveCommercialClientsApi } from '@/api/commercial.api.js'
import Button from '@/components/ui/button.jsx'
import Input from '@/components/ui/input.jsx'
import Select from '@/components/ui/select.jsx'
import Badge from '@/components/ui/badge.jsx'
import { cn } from '@/lib/utils.js'

const QUICK_OPTIONS = [
  { label: '1 jour', value: '1j' },
  { label: '2 jours', value: '2j' },
  { label: '1 semaine', value: '1w' },
  { label: '1 mois', value: '1m' },
]

export default function ReservationModal({ open, onClose }) {
  const qc = useQueryClient()
  const [count, setCount] = useState(10)
  const [quick, setQuick] = useState('')
  const [amount, setAmount] = useState(1)
  const [unit, setUnit] = useState('JOUR')
  const [result, setResult] = useState(null)

  useEffect(() => {
    if (open) {
      setCount(10)
      setQuick('')
      setAmount(1)
      setUnit('JOUR')
      setResult(null)
    }
  }, [open])

  const mutation = useMutation({
    mutationFn: (payload) => reserveCommercialClientsApi(payload),
    onSuccess: (res) => {
      const data = res.data
      setResult(data)
      qc.invalidateQueries({ queryKey: ['commercial-clients'] })
      qc.invalidateQueries({ queryKey: ['mes-clients'] })
      toast.success(`${data.reserved} client(s) réservé(s) avec succès.`)
    },
    onError: (err) => {
      const msg = err?.response?.data?.message || 'Une erreur est survenue.'
      setResult({ error: msg })
      toast.error(msg)
    },
  })

  if (!open) return null

  const handleSubmit = (e) => {
    e.preventDefault()
    setResult(null)
    const payload = { count }
    if (quick) {
      payload.quick = quick
    } else {
      payload.amount = amount
      payload.unit = unit
    }
    mutation.mutate(payload)
  }

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div className="relative w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl border bg-card p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-5">
          <h2 className="text-lg font-semibold text-foreground">Réserver des clients</h2>
          <button onClick={onClose} className="p-1 rounded-md hover:bg-muted" aria-label="Fermer">
            <X className="h-4 w-4" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-bold mb-1">Nombre de clients *</label>
            <Input
              type="number"
              min={1}
              max={1000}
              required
              value={count}
              onChange={(e) => setCount(Number(e.target.value))}
              className="w-32"
            />
          </div>

          <div>
            <label className="block text-sm font-bold mb-2">Durée de réservation</label>
            <div className="flex flex-wrap gap-2">
              {QUICK_OPTIONS.map((opt) => (
                <button
                  key={opt.value}
                  type="button"
                  onClick={() => setQuick(quick === opt.value ? '' : opt.value)}
                  className={cn(
                    'px-3 py-1.5 text-sm rounded-lg border transition-colors',
                    quick === opt.value
                      ? 'border-primary bg-primary text-primary-foreground'
                      : 'border-border bg-background hover:bg-muted text-foreground'
                  )}
                >
                  {opt.label}
                </button>
              ))}
            </div>
          </div>

          <div>
            <label className="block text-sm font-bold mb-1">Ou durée personnalisée</label>
            <div className="flex items-center gap-2">
              <Input
                type="number"
                min={1}
                value={amount}
                onChange={(e) => setAmount(Number(e.target.value))}
                className="w-24"
              />
              <Select value={unit} onChange={(e) => { setUnit(e.target.value); setQuick('') }}>
                <option value="HEURE">Heure(s)</option>
                <option value="JOUR">Jour(s)</option>
                <option value="SEMAINE">Semaine(s)</option>
                <option value="MOIS">Mois</option>
              </Select>
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
                        <Badge variant="warning">{c.status}</Badge>
                        {c.expires_at && (
                          <span className="text-xs text-muted-foreground">
                            {new Date(c.expires_at).toLocaleDateString('fr-FR')}
                          </span>
                        )}
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
