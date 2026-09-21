import { useState, useEffect } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { updateEntrepriseApi } from '@/api/admin.api.js'
import { getApiErrorMessage } from '@/lib/api-errors.js'

export function useEnterpriseUpdateModal(props) {
  const { open, onClose, user: entreprise, queryKey } = props
  const qc = useQueryClient()
  const [error, setError] = useState(null)

  const [form, setForm] = useState({
    email: '', name: '', tax_number: '', phone: '', address: '',
  })

  useEffect(() => {
    if (open && entreprise) {
      setForm({
        email: entreprise.email ?? '',
        name: entreprise.name ?? entreprise.profil?.nom ?? '',
        tax_number: entreprise.tax_number ?? entreprise.profil?.numero_fiscal ?? '',
        phone: entreprise.phone ?? entreprise.profil?.telephone ?? '',
        address: entreprise.address ?? entreprise.profil?.adresse ?? '',
      })
      setError(null)
    }
  }, [open, entreprise?.id])

  useEffect(() => {
    if (!open) return
    const onKey = (e) => { if (e.key === 'Escape') onClose() }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open, onClose])

  const mutation = useMutation({
    mutationFn: (payload) => updateEntrepriseApi(entreprise.id, payload),
    onSuccess: () => {
      toast.success('Entreprise mise à jour avec succès.')
      if (queryKey) qc.invalidateQueries({ queryKey })
      qc.invalidateQueries({ queryKey: ['entreprises-select'] })
      onClose()
    },
    onError: (err) => {
      const msg = getApiErrorMessage(err)
      setError(msg)
      toast.error(msg)
    },
  })

  const set = (key, val) => setForm((p) => ({ ...p, [key]: val }))

  const handleSubmit = (e) => {
    e.preventDefault()
    setError(null)
    if (!form.name.trim()) {
      setError('Le nom de l\'entreprise est requis.')
      return
    }
    const payload = {
      name: form.name.trim(),
      email: form.email.trim() || null,
      tax_number: form.tax_number.trim() || null,
      phone: form.phone.trim() || null,
      address: form.address.trim() || null,
    }
    mutation.mutate(payload)
  }

  return { form, error, isPending: mutation.isPending, set, handleSubmit }
}