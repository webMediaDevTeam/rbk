import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import Badge from '@/components/ui/badge.jsx'
import Button from '@/components/ui/button.jsx'
import Input from '@/components/ui/input.jsx'
import { ROLE_LABELS } from './SettingsSidebar.jsx'
import { useUpdateProfile } from '@/pages/shared/profil/useProfil.js'
import { getApiErrorMessage } from '@/lib/api-errors.js'

export default function UserDataSection({ user, role }) {
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

  return (
    <div className="space-y-6 max-w-2xl">
      <div>
        <h2 className="text-lg font-semibold text-foreground">Données utilisateur</h2>
        <p className="text-sm text-muted-foreground">
          Vos informations personnelles et votre rôle sur la plateforme.
        </p>
      </div>

      <div className="border-b border-border" />

      <form onSubmit={handleSubmit} className="space-y-6">
        <div className="space-y-2">
          <label className="block text-sm font-medium text-foreground">E-mail</label>
          <Input type="email" value={values.email} onChange={handleChange('email')} required />
          <p className="text-xs text-muted-foreground">
            Utilisé pour vous connecter. Un e-mail vérifié ne peut pas être modifié sans validation.
          </p>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div className="space-y-2">
            <label className="block text-sm font-medium text-foreground">Prénom</label>
            <Input type="text" value={values.first_name} onChange={handleChange('first_name')} />
          </div>
          <div className="space-y-2">
            <label className="block text-sm font-medium text-foreground">Nom</label>
            <Input type="text" value={values.last_name} onChange={handleChange('last_name')} />
          </div>
        </div>

        <div className="space-y-2">
          <label className="block text-sm font-medium text-foreground">Téléphone</label>
          <Input type="tel" value={values.phone} onChange={handleChange('phone')} />
        </div>

        <div className="space-y-2">
          <label className="block text-sm font-medium text-foreground">Rôle système</label>
          <div className="pt-1">
            <Badge variant={role === 'SUPER_ADMIN' ? 'info' : 'secondary'}>
              <span className="uppercase text-[11px] font-bold">{roleLabel}</span>
            </Badge>
          </div>
          <p className="text-xs text-muted-foreground">
            Ce rôle détermine les fonctionnalités et les données auxquelles vous avez accès.
          </p>
        </div>

        <div>
          <Button type="submit" disabled={updateMut.isPending}>
            {updateMut.isPending ? 'Enregistrement…' : 'Enregistrer'}
          </Button>
        </div>
      </form>
    </div>
  )
}