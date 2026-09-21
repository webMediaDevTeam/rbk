import { useState, useEffect } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { createUserApi } from '@/api/shared.api.js'
import { listEntreprisesApi } from '@/api/admin.api.js'
import { getApiErrorMessage } from '@/lib/api-errors.js'

export function useCommercialCreateModal(props) {
  const { open, onClose, queryKey } = props
  const qc = useQueryClient()

  const { data: enterprisesData } = useQuery({
    queryKey: ['entreprises-select'],
    queryFn: () => listEntreprisesApi({ per_page: 100 }),
    enabled: open,
  })

  const enterprises = enterprisesData?.data?.entreprises ?? enterprisesData?.data?.utilisateurs ?? []

  const [form, setForm] = useState({
    email: '', first_name: '', last_name: '', phone: '',
    enterprise_id: '', additional_info: '',
  })
  const [error, setError] = useState(null)

  useEffect(() => {
    if (open) {
      setForm({
        email: '', first_name: '', last_name: '', phone: '',
        enterprise_id: '',
        additional_info: '',
      })
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
      enterprise_id: form.enterprise_id ? Number(form.enterprise_id) : undefined,
      additional_info: form.additional_info || undefined,
    }
    mutation.mutate(payload)
  }

  return { form, error, isPending: mutation.isPending, enterprises, set, handleSubmit }
}