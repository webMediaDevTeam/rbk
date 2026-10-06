import { useEffect, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AlertCircle, Loader2, Phone, X } from 'lucide-react'
import { toast } from 'sonner'
import Button from '@/components/ui/button.jsx'
import { cn } from '@/lib/utils.js'
import { adminUpdateClientPhoneApi } from '@/api/commercial.api.js'

/**
 * Modale **« Modifier le numéro »** (Admin / Super Admin) —
 * `PATCH commercials/clients/{id}/phone`.
 *
 * Elle sert aux deux entrées du chantier « Sans téléphone » :
 *   * la **Grande liste** (bouton en fin de ligne, `ProspectTable` / carte) ;
 *   * la **fiche client** (`/prospects/{id}`).
 *
 * Le champ est vide pour un prospect sans numéro (« Ajouter un numéro ») et
 * prérempli sinon. Une saisie **vide efface** le numéro (action assumée :
 * le client repasse « Sans téléphone ») — le serveur nettoie et reformate.
 * Après enregistrement, toutes les vues qui portent une ligne ou un compteur
 * de ce client sont invalidées.
 */
const INVALIDATIONS = [
  ['admin-clients-history'],
  ['prospect-kpis'],
  ['commercial-prospects'],
  ['admin-client'],
  ['commercial-prospect'],
  ['dashboard-stats'],
]

export default function PhoneEditModal({ open, client, onClose }) {
  const qc = useQueryClient()
  const [phone, setPhone] = useState('')
  const [error, setError] = useState(null)

  const clientId = client?.id ?? null

  // Réinitialisation à chaque ouverture (et à chaque changement de client).
  useEffect(() => {
    if (open) {
      setPhone(client?.phone ?? '')
      setError(null)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, clientId])

  const mutation = useMutation({
    mutationFn: () => adminUpdateClientPhoneApi(clientId, phone.trim()),
    onSuccess: (res) => {
      toast.success(res?.data?.message || 'Numéro enregistré.')
      INVALIDATIONS.forEach((queryKey) => qc.invalidateQueries({ queryKey }))
      onClose()
    },
    onError: (err) => {
      const msg = err?.response?.data?.message || 'Une erreur est survenue.'
      setError(msg)
      toast.error(msg)
    },
  })

  if (!open || !client) return null

  const isCreation = !phone.trim() && !client.phone

  const handleSubmit = (e) => {
    e.preventDefault()
    setError(null)
    mutation.mutate()
  }

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div
        className="relative w-full max-w-md rounded-2xl bg-card p-6 shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between mb-5">
          <h2 className="text-lg font-semibold text-foreground">
            {isCreation ? 'Ajouter un numéro' : 'Modifier le numéro'}
          </h2>
          <button onClick={onClose} className="p-1 rounded-md hover:bg-muted" aria-label="Fermer">
            <X className="h-4 w-4" />
          </button>
        </div>

        <p className="text-sm text-muted-foreground mb-4">
          {client.enterprise_name || client.name}
          {' — '}
          {client.phone ? 'numéro actuel : ' : 'ce prospect est « Sans téléphone ».'}
          {client.phone && client.phone}
        </p>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label htmlFor="client-phone" className="block text-sm font-bold mb-1">
              Numéro de téléphone
            </label>
            <div className="relative">
              <Phone className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <input
                id="client-phone"
                type="tel"
                inputMode="tel"
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
                placeholder="514 555-0100"
                autoFocus
                className={cn(
                  'flex w-full rounded-md border border-input bg-background pl-9 pr-3 py-2 text-sm ring-offset-background',
                  'placeholder:text-muted-foreground',
                  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
                )}
              />
            </div>
            <p className="text-xs text-muted-foreground mt-1.5">
              Champ vide = numéro retiré (le prospect repasse « Sans téléphone »).
            </p>
          </div>

          {error && (
            <div className="flex items-start gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
              <AlertCircle className="h-4 w-4 mt-0.5 shrink-0" />
              <span>{error}</span>
            </div>
          )}

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={onClose} disabled={mutation.isPending}>
              Annuler
            </Button>
            <Button type="submit" disabled={mutation.isPending}>
              {mutation.isPending && <Loader2 className="h-4 w-4 animate-spin mr-2" />}
              Enregistrer
            </Button>
          </div>
        </form>
      </div>
    </div>
  )
}
