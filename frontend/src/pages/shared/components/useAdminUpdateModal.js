import { useState, useEffect } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { updateUserApi } from '@/api/shared.api.js'
import { getApiErrorMessage } from '@/lib/api-errors.js'

export function useAdminUpdateModal(props) {
  const { open, onClose, user, queryKey } = props
  const qc = useQueryClient()
  const [error, setError] = useState(null)

  const [form, setForm] = useState({
    email: '', first_name: '', last_name: '', phone: '',
  })

  useEffect(() => {
    if (open && user) {
      setForm({
        email: user.email ?? '',
        first_name: user.first_name ?? '',
        last_name: user.last_name ?? '',
        phone: user.phone ?? '',
      })
      setError(null)
    }
  }, [open, user?.id])

  const mutation = useMutation({
    mutationFn: (payload) => updateUserApi(user.id, payload),
    onSuccess: () => {
      toast.success('Admin mis à jour.')
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
      email: form.email,
      first_name: form.first_name || null,
      last_name: form.last_name || null,
      phone: form.phone || null,
    }
    mutation.mutate(payload)
  }

  return { form, error, isPending: mutation.isPending, set, handleSubmit }
}