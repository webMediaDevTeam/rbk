import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { ROLE_LABELS } from './SettingsSidebar.jsx'
import { useUpdateProfile } from '@/pages/shared/profil/useProfil.js'
import { getApiErrorMessage } from '@/lib/api-errors.js'

export function useUserDataSection({ user, role }) {
  const isCommercial = role === 'COMERCIAL'
  const [values, setValues] = useState({
    email: '',
    first_name: '',
    last_name: '',
    phone: '',
  })

  useEffect(() => {
    if (!user) return
    setValues({
      email: user.email ?? '',
      first_name: user.first_name ?? user.profil?.prenom ?? '',
      last_name: user.last_name ?? user.profil?.nom ?? '',
      phone: user.phone ?? user.profil?.telephone ?? '',
    })
  }, [user])

  const updateMut = useUpdateProfile()

  const handleChange = (key) => (e) => {
    setValues((prev) => ({ ...prev, [key]: e.target.value }))
  }

  const handleSubmit = (e) => {
    e.preventDefault()
    updateMut.mutate(
      {
        id: user.id,
        email: values.email,
        first_name: values.first_name || null,
        last_name: values.last_name || null,
        phone: values.phone || null,
      },
      {
        onSuccess: () => toast.success('Profil mis à jour.'),
        onError: (err) => toast.error(getApiErrorMessage(err)),
      }
    )
  }

  const roleLabel = ROLE_LABELS[role] ?? role

  return {
    values,
    isCommercial,
    isPending: updateMut.isPending,
    roleLabel,
    handleChange,
    handleSubmit,
  }
}