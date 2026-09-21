import { AlertCircle, Loader2, X } from 'lucide-react'
import Button from '@/components/ui/button.jsx'
import Input from '@/components/ui/input.jsx'
import { useAdminUpdateModal } from './useAdminUpdateModal.js'

export default function AdminUpdateModal({ open, onClose, user, queryKey }) {
  const { form, error, isPending, set, handleSubmit } = useAdminUpdateModal({ open, onClose, user, queryKey })

  if (!open || !user) return null

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div className="relative w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl bg-card p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-5">
          <h2 className="text-lg font-semibold text-foreground">Modifier l'admin</h2>
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
              <label className="block text-sm font-bold mb-1">Prénom</label>
              <Input type="text" value={form.first_name} onChange={(e) => set('first_name', e.target.value)} />
            </div>
            <div>
              <label className="block text-sm font-bold mb-1">Nom</label>
              <Input type="text" value={form.last_name} onChange={(e) => set('last_name', e.target.value)} />
            </div>
          </div>

          <div>
            <label className="block text-sm font-bold mb-1">Téléphone</label>
            <Input type="tel" value={form.phone} onChange={(e) => set('phone', e.target.value)} />
          </div>

          {error && (
            <div className="flex items-start gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
              <AlertCircle className="h-4 w-4 mt-0.5 shrink-0" />
              <span>{error}</span>
            </div>
          )}

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={onClose} disabled={isPending}>Annuler</Button>
            <Button type="submit" disabled={isPending}>
              {isPending && <Loader2 className="h-4 w-4 animate-spin mr-2" />}
              Enregistrer
            </Button>
          </div>
        </form>
      </div>
    </div>
  )
}