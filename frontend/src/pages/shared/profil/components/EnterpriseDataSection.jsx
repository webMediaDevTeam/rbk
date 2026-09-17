import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { Loader2 } from 'lucide-react'
import Button from '@/components/ui/button.jsx'
import Input from '@/components/ui/input.jsx'
import { Textarea } from '@/components/ui/textarea.jsx'
import { useUpdateProfile } from '@/pages/shared/profil/useProfil.js'
import { getApiErrorMessage } from '@/lib/api-errors.js'

export default function EnterpriseDataSection({ user }) {
  const profil = user?.profil ?? {}

  const [values, setValues] = useState({
    name: '',
    tax_number: '',
    phone: '',
    address: '',
  })

  useEffect(() => {
    setValues({
      name: profil.nom ?? '',
      tax_number: profil.numero_fiscal ?? '',
      phone: profil.telephone ?? '',
      address: profil.adresse ?? '',
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
        name: values.name,
        tax_number: values.tax_number || null,
        phone: values.phone || null,
        address: values.address || null,
      },
      {
        onSuccess: () => toast.success('Données entreprise mises à jour.'),
        onError: (err) => toast.error(getApiErrorMessage(err)),
      }
    )
  }

  return (
    <div className="space-y-6 max-w-2xl">
      <div>
        <h2 className="text-lg font-semibold text-foreground">Données entreprise</h2>
        <p className="text-sm text-muted-foreground">
          Coordonnées officielles de votre entreprise enregistrées sur la plateforme.
        </p>
      </div>

      <div className="border-b border-border" />

      <form onSubmit={handleSubmit} className="space-y-6">
        <div className="space-y-2">
          <label className="block text-sm font-medium text-foreground">Nom de l’entreprise</label>
          <Input type="text" value={values.name} onChange={handleChange('name')} required />
        </div>

        <div className="space-y-2">
          <label className="block text-sm font-medium text-foreground">Numéro fiscal (RC)</label>
          <Input type="text" value={values.tax_number} onChange={handleChange('tax_number')} />
        </div>

        <div className="space-y-2">
          <label className="block text-sm font-medium text-foreground">Téléphone</label>
          <Input type="tel" value={values.phone} onChange={handleChange('phone')} />
        </div>

        <div className="space-y-2">
          <label className="block text-sm font-medium text-foreground">Adresse</label>
          <Textarea
            rows={3}
            value={values.address}
            onChange={handleChange('address')}
            placeholder="Adresse du siège social…"
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