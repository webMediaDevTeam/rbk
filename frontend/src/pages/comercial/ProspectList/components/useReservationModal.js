import { useState, useEffect } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  reserveCommercialProspectsApi,
  releasePendingReservationApi,
} from '@/api/commercial.api.js'

// Nombre de prospects : 50 / 80 / 100 / 120 uniquement (pas de nombre
// libre) — validé côté serveur par `in:50,80,100,120` (docs/RULES.md §7.2).
export const COUNT_OPTIONS = [50, 80, 100, 120]

/** Vues impactées par une libération de liste (clients de nouveau `AVAILABLE`). */
const RELEASE_INVALIDATIONS = [
  ['commercial-prospects'],
  ['reservation-groups'],
  ['reservation-group'],
  ['active-reservations-count'],
  ['prospect-kpis'],
  ['admin-clients-history'],
  ['dashboard-stats'],
]

export function useReservationModal({ open, onClose, filters = {} }) {
  const qc = useQueryClient()
  const [count, setCount] = useState(COUNT_OPTIONS[0])
  const [result, setResult] = useState(null)

  useEffect(() => {
    if (open) {
      setCount(COUNT_OPTIONS[0])
      setResult(null)
    }
  }, [open])

  const mutation = useMutation({
    mutationFn: (payload) => reserveCommercialProspectsApi(payload),
    onSuccess: (res) => {
      const data = res.data
      setResult(data)
      qc.invalidateQueries({ queryKey: ['commercial-prospects'] })
      qc.invalidateQueries({ queryKey: ['reservation-groups'] })
      qc.invalidateQueries({ queryKey: ['active-reservations-count'] })
      qc.invalidateQueries({ queryKey: ['prospect-kpis'] })
      toast.success(`${data.reserved} prospect(s) réservé(s) avec succès.`)
    },
    onError: (err) => {
      const msg = err?.response?.data?.message || 'Une erreur est survenue.'
      setResult({ error: msg })
      toast.error(msg)
    },
  })

  // **Libérer la liste** : les prospects « en attente » redeviennent
  // disponibles pour tout le monde. `pending` / `can_reserve` reviennent du
  // serveur : la garde « traitement en cours » saute et le bouton
  // « Réserver » se réactive sans recharger la page.
  const releaseMutation = useMutation({
    mutationFn: () => releasePendingReservationApi(),
    onSuccess: (res) => {
      const data = res.data
      RELEASE_INVALIDATIONS.forEach((queryKey) => qc.invalidateQueries({ queryKey }))
      setResult(null)
      toast.success(data?.message || 'Liste libérée : les prospects sont de nouveau disponibles.')
    },
    onError: (err) => {
      const msg = err?.response?.data?.message || 'Une erreur est survenue.'
      setResult({ error: msg })
      toast.error(msg)
    },
  })

  const handleSubmit = (e) => {
    e.preventDefault()
    setResult(null)
    // `filters` = filtres + tri de la page Prospects : le serveur réutilise
    // exactement la même requête que l'écran pour préparer le lot
    // (Client::scopeProspectList). `page` / `per_page` sont ignorés.
    mutation.mutate({ count, ...filters })
  }

  return {
    open,
    onClose,
    count,
    setCount,
    result,
    mutation,
    releaseMutation,
    handleSubmit,
  }
}
