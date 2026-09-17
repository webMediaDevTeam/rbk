import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AlertCircle, Loader2, X } from 'lucide-react'
import { toast } from 'sonner'
import Button from '../../../components/ui/button.jsx'
import Input from '../../../components/ui/input.jsx'
import { createUserApi } from '../../../api/shared.api.js'
import { getApiErrorMessage } from '../../../lib/api-errors.js'

export default function EnterpriseCreateModal({ open, onClose, queryKey }) {
  const qc = useQueryClient()
  const [form, setForm] = useState({
    email: '', name: '', tax_number: '', phone: '', address: '',
  })
  const [error, setError] = useState(null)

  const mutation = useMutation({
    mutationFn: (payload) => createUserApi(payload),
    onSuccess: () => {
      toast.success('Entreprise créée.')
      qc.invalidateQueries({ queryKey })
      onClose()
    },
    onError: (err) => {
      const msg = getApiErrorMessage(err)
      setError(msg)
      toast.error(msg)
    },
  })

  if (!open) return null

  const set = (key, val) => setForm((p) => ({ ...p, [key]: val }))

  const handleSubmit = (e) => {
    e.preventDefault()
    setError(null)
    if (!form.email) {
      setError('L\'adresse e-mail est requise.')
      return
    }
    if (!form.name) {
      setError('Le nom de l\'entreprise est requis.')
      return
    }
    const payload = {
      role: 'ENTREPRISE',
      email: form.email,
      name: form.name,
      tax_number: form.tax_number || undefined,
      phone: form.phone || undefined,
      address: form.address || undefined,
    }
    mutation.mutate(payload)
  }

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div className="relative w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl border bg-card p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-5">
          <h2 className="text-lg font-semibold text-foreground">Créer une entreprise</h2>
          <button onClick={onClose} className="p-1 rounded-md hover:bg-muted" aria-label="Fermer">
            <X className="h-4 w-4" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-bold mb-1">Adresse e-mail *</label>
            <Input type="email" required value={form.email} onChange={(e) => set('email', e.target.value)} placeholder="email@entreprise.com" />
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-bold mb-1">Nom de l'entreprise *</label>
              <Input type="text" required value={form.name} onChange={(e) => set('name', e.target.value)} placeholder="Nom" />
            </div>
            <div>
              <label className="block text-sm font-bold mb-1">Numéro fiscal</label>
              <Input type="text" value={form.tax_number} onChange={(e) => set('tax_number', e.target.value)} placeholder="NIF" />
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-bold mb-1">Téléphone</label>
              <Input type="tel" value={form.phone} onChange={(e) => set('phone', e.target.value)} placeholder="418-555-0100" />
            </div>
            <div>
              <label className="block text-sm font-bold mb-1">Adresse</label>
              <Input type="text" value={form.address} onChange={(e) => set('address', e.target.value)} placeholder="123 rue Principale" />
            </div>
          </div>

          {/* password fields removed: account will be created without raw password */}

          {error && (
            <div className="flex items-start gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
              <AlertCircle className="h-4 w-4 mt-0.5 shrink-0" />
              <span>{error}</span>
            </div>
          )}

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={onClose} disabled={mutation.isPending}>Annuler</Button>
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