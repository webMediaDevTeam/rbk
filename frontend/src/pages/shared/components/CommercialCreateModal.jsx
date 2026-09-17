import { useState, useEffect } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertCircle, Loader2, X } from 'lucide-react'
import { toast } from 'sonner'
import Button from '../../../components/ui/button.jsx'
import Input from '../../../components/ui/input.jsx'
import Select from '../../../components/ui/select.jsx'
import { createUserApi } from '../../../api/shared.api.js'
import { listEntreprisesApi } from '../../../api/admin.api.js'
import { getApiErrorMessage } from '../../../lib/api-errors.js'

export default function CommercialCreateModal({ open, onClose, queryKey, currentUser }) {
  const qc = useQueryClient()
  const isEnterprise = currentUser?.role === 'ENTREPRISE'
  const enterpriseId = currentUser?.profil?.id ?? currentUser?.id

  const { data: enterprisesData } = useQuery({
    queryKey: ['entreprises-select'],
    queryFn: () => listEntreprisesApi({ per_page: 100 }),
    enabled: open && !isEnterprise,
  })

  const enterprises = enterprisesData?.data?.utilisateurs ?? []

  const [form, setForm] = useState({
    email: '', first_name: '', last_name: '', phone: '',
    enterprise_id: '', additional_info: '',
  })
  const [error, setError] = useState(null)

  useEffect(() => {
    if (open) {
      setForm({
        email: '', first_name: '', last_name: '', phone: '',
        enterprise_id: isEnterprise ? (enterpriseId ?? '') : '',
        additional_info: '',
      })
      setError(null)
    }
  }, [open])

  const mutation = useMutation({
    mutationFn: (payload) => createUserApi(payload),
    onSuccess: () => {
      toast.success('Commercial créé.')
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
    if (!form.first_name || !form.last_name) {
      setError('Le prénom et le nom sont requis.')
      return
    }
    const payload = {
      role: 'COMERCIAL',
      email: form.email,
      first_name: form.first_name,
      last_name: form.last_name,
      phone: form.phone || undefined,
      enterprise_id: form.enterprise_id || undefined,
      additional_info: form.additional_info || undefined,
    }
    mutation.mutate(payload)
  }

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div className="relative w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl border bg-card p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between mb-5">
          <h2 className="text-lg font-semibold text-foreground">Créer un commercial</h2>
          <button onClick={onClose} className="p-1 rounded-md hover:bg-muted" aria-label="Fermer">
            <X className="h-4 w-4" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-bold mb-1">Adresse e-mail *</label>
            <Input type="email" required value={form.email} onChange={(e) => set('email', e.target.value)} placeholder="email@commercial.com" />
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-bold mb-1">Prénom *</label>
              <Input type="text" required value={form.first_name} onChange={(e) => set('first_name', e.target.value)} placeholder="Prénom" />
            </div>
            <div>
              <label className="block text-sm font-bold mb-1">Nom *</label>
              <Input type="text" required value={form.last_name} onChange={(e) => set('last_name', e.target.value)} placeholder="Nom" />
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-bold mb-1">Téléphone</label>
              <Input type="tel" value={form.phone} onChange={(e) => set('phone', e.target.value)} placeholder="418-555-0200" />
            </div>
            <div>
              <label className="block text-sm font-bold mb-1">Entreprise</label>
              {isEnterprise ? (
                <Input type="text" disabled value={enterpriseId ?? ''} />
              ) : (
                <Select value={form.enterprise_id} onChange={(e) => set('enterprise_id', e.target.value)}>
                  <option value="">Sélectionner une entreprise</option>
                  {enterprises.map((ent) => (
                    <option key={ent.id} value={ent.id}>{ent.profil?.nom ?? ent.email}</option>
                  ))}
                </Select>
              )}
            </div>
          </div>

          <div>
            <label className="block text-sm font-bold mb-1">Informations supplémentaires</label>
            <Input type="text" value={form.additional_info} onChange={(e) => set('additional_info', e.target.value)} placeholder="Notes..." />
          </div>

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