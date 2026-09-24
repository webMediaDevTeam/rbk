import { useState, useEffect } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { updateUserApi } from '@/api/shared.api.js'
import { listEntreprisesApi } from '@/api/admin.api.js'
import { getApiErrorMessage } from '@/lib/api-errors.js'

export function useCommercialUpdateModal(props) {
  const { open, onClose, user, queryKey } = props
  const qc = useQueryClient()
  const [error, setError] = useState(null)

  const { data: enterprisesData } = useQuery({
    queryKey: ['entreprises-select'],
    queryFn: () => listEntreprisesApi({ per_page: 100 }),
    enabled: open,
  })

  const enterprises = enterprisesData?.data?.entreprises ?? enterprisesData?.data?.utilisateurs ?? []

  const profil = user?.profil
  const [form, setForm] = useState({
    email: '', first_name: '', last_name: '', phone: '',
    enterprise_id: '', additional_info: '',
  })

  useEffect(() => {
    if (open && user) {
      setForm({
        email: user.email ?? '',
        first_name: user.first_name ?? profil?.prenom ?? '',
        last_name: user.last_name ?? profil?.nom ?? '',
        phone: user.phone ?? profil?.telephone ?? '',
        enterprise_id: profil?.entreprise_id ? String(profil.entreprise_id) : '',
        additional_info: profil?.info_supp ?? '',
      })
      setError(null)
    }
  }, [open, user?.id])

  useEffect(() => {
    if (!open) return
    const onKey = (e) => { if (e.key === 'Escape') onClose() }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open, onClose])

  const mutation = useMutation({
    mutationFn: (payload) => updateUserApi(user.id, payload),
    onSuccess: () => {
      toast.success('Employé mis à jour.')
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
      email: form.email,
      first_name: form.first_name,
      last_name: form.last_name,
      phone: form.phone || null,
      enterprise_id: form.enterprise_id ? Number(form.enterprise_id) : null,
      additional_info: form.additional_info || null,
    }
    mutation.mutate(payload)
  }

  return { form, error, isPending: mutation.isPending, enterprises, set, handleSubmit }
}