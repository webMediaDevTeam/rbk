import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { createUserApi } from '@/api/shared.api.js'
import { getApiErrorMessage } from '@/lib/api-errors.js'

export function useAdminCreateModal(props) {
  const { open, onClose, queryKey } = props
  const qc = useQueryClient()
  const [form, setForm] = useState({
    email: '', first_name: '', last_name: '', phone: '',
  })
  const [error, setError] = useState(null)

  const mutation = useMutation({
    mutationFn: (payload) => createUserApi(payload),
    onSuccess: () => {
      toast.success('Admin créé.')
      qc.invalidateQueries({ queryKey })
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
    if (!form.email) {
      setError('L\'adresse e-mail est requise.')
      return
    }
    const payload = {
      role: 'ADMIN',
      email: form.email,
      first_name: form.first_name || undefined,
      last_name: form.last_name || undefined,
      phone: form.phone || undefined,
    }
    mutation.mutate(payload)
  }

  return { form, error, isPending: mutation.isPending, set, handleSubmit }
}