import { useState, useEffect } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AlertCircle, Loader2, X } from 'lucide-react'
import { toast } from 'sonner'
import Button from '@/components/ui/button.jsx'
import Input from '@/components/ui/input.jsx'
import { updateUserApi } from '@/api/shared.api.js'
import { getApiErrorMessage } from '@/lib/api-errors.js'

export default function EnterpriseUpdateModal({ open, onClose, user, queryKey }) {
  const qc = useQueryClient()
  const [error, setError] = useState(null)

  const profil = user?.profil
  const [form, setForm] = useState({
    email: '', name: '', tax_number: '', phone: '', address: '',
  })

  useEffect(() => {
    if (open && user) {
      setForm({
        email: user.email ?? '',
        name: profil?.nom ?? '',
        tax_number: profil?.numero_fiscal ?? '',
        phone: profil?.telephone ?? '',
        address: profil?.adresse ?? '',
      })
      setError(null)
    }
  }, [open, user?.id])

  const mutation = useMutation({
    mutationFn: (payload) => updateUserApi(user.id, payload),
    onSuccess: () => {
      toast.success('Entreprise mise à jour.')
      qc.invalidateQueries({ queryKey })
      onClose()
    },
    onError: (err) => {
      const msg = getApiErrorMessage(err)
      setError(msg)
      toast.error(msg)
    },
  })

  if (!open || !user) return null

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
      email: form.email,
      name: form.name,
      tax_number: form.tax_number || null,
      phone: form.phone || null,
      address: form.address || null,
    }
    mutation.mutate(payload)
  }

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div className="relative w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl border bg-card p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-5">
          <h2 className="text-lg font-semibold text-foreground">Modifier l'entreprise</h2>
          <button onClick={onClose} className="p-1 rounded-md hover:bg-muted" aria-label="Fermer">
            <X className="h-4 w-4" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-bold mb-1">Adresse e-mail *</label>
            <Input type="email" required value={form.email} onChange={(e) => set('email', e.target.value)} />
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-bold mb-1">Nom de l'entreprise *</label>
              <Input type="text" required value={form.name} onChange={(e) => set('name', e.target.value)} />
            </div>
            <div>
              <label className="block text-sm font-bold mb-1">Numéro fiscal</label>
              <Input type="text" value={form.tax_number} onChange={(e) => set('tax_number', e.target.value)} />
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-bold mb-1">Téléphone</label>
              <Input type="tel" value={form.phone} onChange={(e) => set('phone', e.target.value)} />
            </div>
            <div>
              <label className="block text-sm font-bold mb-1">Adresse</label>
              <Input type="text" value={form.address} onChange={(e) => set('address', e.target.value)} />
            </div>
          </div>

          {/* removed mot_de_passe input - password updates handled elsewhere */}

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