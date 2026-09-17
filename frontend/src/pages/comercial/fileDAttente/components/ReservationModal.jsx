import { useState, useEffect } from 'react'
import { reserveCommercialClientsApi } from '../../../../api/commercial.api.js'
import { X } from 'lucide-react'
import Button from '../../../../components/ui/button.jsx'
import Input from '../../../../components/ui/input.jsx'

export default function ReservationModal({ open = true, onClose }) {
  const [count, setCount] = useState(10)
  const [quick, setQuick] = useState('')
  const [amount, setAmount] = useState(1)
  const [unit, setUnit] = useState('JOUR')
  const [result, setResult] = useState(null)
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    if (!open) return
    const onKey = (e) => { if (e.key === 'Escape') onClose() }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open, onClose])

  const submit = async () => {
    setLoading(true)
    setResult(null)
    try {
      const payload = { count }
      if (quick) payload.quick = quick
      else {
        payload.amount = amount
        payload.unit = unit
      }
      const res = await reserveCommercialClientsApi(payload)
      setResult(res.data)
    } catch (err) {
      setResult({ error: err?.response?.data || err.message })
    } finally {
      setLoading(false)
    }
  }

  if (!open) return null

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div className="relative w-full max-w-xl max-h-[90vh] overflow-y-auto rounded-2xl border bg-card p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-lg font-semibold text-foreground">Réserver des clients</h3>
          <button onClick={onClose} className="p-1 rounded-md hover:bg-muted" aria-label="Fermer">
            <X className="h-4 w-4" />
          </button>
        </div>

        <div className="space-y-3">
          <div>
            <label className="block text-sm font-bold mb-1">Nombre de clients</label>
            <Input type="number" value={count} onChange={(e) => setCount(Number(e.target.value))} className="w-24" />
          </div>

          <div>
            <label className="block text-sm font-bold mb-1">Durée - raccourcis</label>
            <div className="flex gap-2">
              <button type="button" onClick={() => setQuick('1j')} className="btn">1j</button>
              <button type="button" onClick={() => setQuick('2j')} className="btn">2j</button>
              <button type="button" onClick={() => setQuick('1w')} className="btn">1w</button>
              <button type="button" onClick={() => setQuick('')} className="btn">Clear</button>
            </div>
          </div>

          <div>
            <label className="block text-sm font-bold mb-1">Ou personnalisé</label>
            <div className="flex items-center gap-2">
              <Input type="number" value={amount} onChange={(e) => setAmount(Number(e.target.value))} className="w-24" />
              <select value={unit} onChange={(e) => setUnit(e.target.value)} className="rounded-md border border-input bg-background px-3 py-2 text-sm">
                <option value="HEURE">HEURE</option>
                <option value="JOUR">JOUR</option>
                <option value="SEMAINE">SEMAINE</option>
                <option value="MOIS">MOIS</option>
              </select>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <Button onClick={submit} disabled={loading}>{loading ? 'En cours...' : 'Réserver'}</Button>
            <Button type="button" variant="secondary" onClick={onClose}>Fermer</Button>
          </div>

          {result && (
            <div className="mt-2">
              <h4 className="text-sm font-medium">Résultat</h4>
              {result.error ? (
                <pre className="text-red-600">{JSON.stringify(result.error, null, 2)}</pre>
              ) : (
                <div>
                  <p>Demandé: {result.requested}</p>
                  <p>Réservés: {result.reserved}</p>
                  <p>Conflits: {result.conflicts?.length ?? 0}</p>
                  {result.conflicts && result.conflicts.length > 0 && (
                    <div className="mt-2 overflow-auto">
                      <table className="w-full table-auto text-sm">
                        <thead>
                          <tr className="text-left text-slate-600">
                            <th className="pb-2">Client</th>
                            <th className="pb-2">Statut</th>
                            <th className="pb-2">Réservé par</th>
                            <th className="pb-2">Expire le</th>
                          </tr>
                        </thead>
                        <tbody>
                          {result.conflicts.map((c, idx) => (
                            <tr key={c.client_id} className={idx % 2 === 0 ? 'bg-transparent' : 'bg-black/5'}>
                              <td className="py-2">{c.name}</td>
                              <td className="py-2"><span className="rounded-full bg-black/5 px-2 py-0.5 text-xs text-black">{c.status}</span></td>
                              <td className="py-2">{c.reserved_by}</td>
                              <td className="py-2">{c.expires_at}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                </div>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
