import { useState, useEffect } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { createEntrepriseApi } from '@/api/admin.api.js'
import { getApiErrorMessage } from '@/lib/api-errors.js'

export function useEnterpriseCreateModal(props) {
  const { open, onClose, queryKey } = props
  const qc = useQueryClient()
  const [form, setForm] = useState({
    name: '', email: '', tax_number: '', phone: '', address: '',
  })
  const [error, setError] = useState(null)

  useEffect(() => {
    if (open) {
      setForm({ name: '', email: '', tax_number: '', phone: '', address: '' })
      setError(null)
    }
  }, [open])

  useEffect(() => {
    if (!open) return
    const onKey = (e) => { if (e.key === 'Escape') onClose() }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open, onClose])

  const mutation = useMutation({
    mutationFn: (payload) => createEntrepriseApi(payload),
    onSuccess: () => {
      toast.success('Entreprise créée avec succès.')
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
      email: form.email.trim() || undefined,
      tax_number: form.tax_number.trim() || undefined,
      phone: form.phone.trim() || undefined,
      address: form.address.trim() || undefined,
    }
    mutation.mutate(payload)
  }

  return { form, error, isPending: mutation.isPending, set, handleSubmit }
}