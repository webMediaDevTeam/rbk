import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import Button from '@/components/ui/button.jsx'
import Input from '@/components/ui/input.jsx'
import { Textarea } from '@/components/ui/textarea.jsx'
import { useUpdateProfile } from '@/pages/shared/profil/useProfil.js'
import { getApiErrorMessage } from '@/lib/api-errors.js'

export default function EmployeeDataSection({ user }) {
  const profil = user?.profil ?? {}

  const [values, setValues] = useState({
    first_name: '',
    last_name: '',
    phone: '',
    additional_info: '',
  })

  useEffect(() => {
    setValues({
      first_name: profil.prenom ?? '',
      last_name: profil.nom ?? '',
      phone: profil.telephone ?? '',
      additional_info: profil.info_supp ?? '',
    })
  }, [user?.id])

  const updateMut = useUpdateProfile()

  const handleChange = (key) => (e) => {
    setValues((prev) => ({ ...prev, [key]: e.target.value }))
  }

  const handleSubmit = (e) => {
    e.preventDefault()
    updateMut.mutate(
      {
        id: user.id,
        first_name: values.first_name || null,
        last_name: values.last_name || null,
        phone: values.phone || null,
        additional_info: values.additional_info || null,
      },
      {
        onSuccess: () => toast.success('Données employé mises à jour.'),
        onError: (err) => toast.error(getApiErrorMessage(err)),
      }
    )
  }

  return (
    <div className="space-y-6 max-w-2xl">
      <div>
        <h2 className="text-lg font-semibold text-foreground">Données employé</h2>
        <p className="text-sm text-muted-foreground">
          Informations liées à votre statut d’employé au sein de l’entreprise.
        </p>
      </div>

      <div className="border-b border-border" />

      <div className="rounded-xl border border-border px-4 py-3 space-y-3 text-sm">
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-1 sm:gap-4 py-1">
          <span className="text-muted-foreground">Identifiant agent</span>
          <span className="font-semibold text-foreground sm:col-span-2 break-words">{profil.id || '—'}</span>
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-1 sm:gap-4 py-1">
          <span className="text-muted-foreground">Entreprise (ID)</span>
          <span className="font-semibold text-foreground sm:col-span-2 break-words">{profil.entreprise_id || '—'}</span>
        </div>
      </div>

      <form onSubmit={handleSubmit} className="space-y-6">
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
          <label className="block text-sm font-medium text-foreground">Zone de vente & détails du contrat</label>
          <Textarea
            rows={3}
            value={values.additional_info}
            onChange={handleChange('additional_info')}
            placeholder="Décrivez votre zone de vente et les détails de votre contrat…"
          />
        </div>

        <div>
          <Button type="submit" disabled={updateMut.isPending}>
            {updateMut.isPending ? (
              <><Loader2 className="h-4 w-4 animate-spin" /> Enregistrement…</>
            ) : 'Enregistrer'}
          </Button>
        </div>
      </form>
    </div>
  )
}