import { Info } from 'lucide-react'
import Badge from '@/components/ui/badge.jsx'
import Button from '@/components/ui/button.jsx'
import Input from '@/components/ui/input.jsx'
import { Alert, AlertDescription } from '@/components/ui/alert.jsx'
import { useUserDataSection } from './useUserDataSection.js'

export default function UserDataSection({ user, role }) {
  const { values, isCommercial, isPending, roleLabel, handleChange, handleSubmit } = useUserDataSection({ user, role })

  return (
    <div className="space-y-6 max-w-2xl">
      <div>
        <h2 className="text-lg font-semibold text-foreground">Données utilisateur</h2>
        <p className="text-sm text-muted-foreground">
          Vos informations personnelles et votre rôle sur la plateforme.
        </p>
      </div>

      <div className="border-b border-border" />

      {isCommercial && (
        <Alert>
          <Info className="h-4 w-4" />
          <AlertDescription>
            Ces informations sont en lecture seule. Pour toute modification, veuillez contacter votre administrateur.
          </AlertDescription>
        </Alert>
      )}

      <form onSubmit={handleSubmit} className="space-y-6">
        <div className="space-y-2">
          <label className="block text-sm font-medium text-foreground">E-mail</label>
          <Input type="email" value={values.email} onChange={handleChange('email')} disabled={isCommercial} readOnly={isCommercial} />
          <p className="text-xs text-muted-foreground">
            Utilisé pour vous connecter. Un e-mail vérifié ne peut pas être modifié sans validation.
          </p>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div className="space-y-2">
            <label className="block text-sm font-medium text-foreground">Prénom</label>
            <Input type="text" value={values.first_name} onChange={handleChange('first_name')} disabled={isCommercial} readOnly={isCommercial} />
          </div>
          <div className="space-y-2">
            <label className="block text-sm font-medium text-foreground">Nom</label>
            <Input type="text" value={values.last_name} onChange={handleChange('last_name')} disabled={isCommercial} readOnly={isCommercial} />
          </div>
        </div>

        <div className="space-y-2">
          <label className="block text-sm font-medium text-foreground">Téléphone</label>
          <Input type="tel" value={values.phone} onChange={handleChange('phone')} disabled={isCommercial} readOnly={isCommercial} />
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

        {!isCommercial && (
          <div>
            <Button type="submit" disabled={isPending}>
              {isPending ? 'Enregistrement…' : 'Enregistrer'}
            </Button>
          </div>
        )}
      </form>
    </div>
  )
}