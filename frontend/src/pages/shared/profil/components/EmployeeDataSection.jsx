import { Building2, Info } from 'lucide-react'
import Input from '@/components/ui/input.jsx'
import { Textarea } from '@/components/ui/textarea.jsx'
import { Alert, AlertDescription } from '@/components/ui/alert.jsx'
import { useEmployeeDataSection } from './useEmployeeDataSection.js'

function DetailRow({ label, value }) {
  return (
    <div className="space-y-1">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="font-medium text-foreground break-words">{value || '—'}</dd>
    </div>
  )
}

export default function EmployeeDataSection({ user }) {
  const { entreprise, prenom, nom, telephone, infoSupp, entrepriseName, entrepriseLogoAlt } = useEmployeeDataSection({ user })

  return (
    <div className="space-y-6 max-w-2xl">
      <div>
        <h2 className="text-lg font-semibold text-foreground">Données employé</h2>
        <p className="text-sm text-muted-foreground">
          Informations liées à votre statut d’employé au sein de l’entreprise.
        </p>
      </div>

      <div className="border-b border-border" />

      <Alert>
        <Info className="h-4 w-4" />
        <AlertDescription>
          Ces informations sont en lecture seule. Pour toute modification, veuillez contacter votre administrateur.
        </AlertDescription>
      </Alert>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div className="space-y-2">
          <label className="block text-sm font-medium text-foreground">Prénom</label>
          <Input type="text" value={prenom} disabled readOnly />
        </div>
        <div className="space-y-2">
          <label className="block text-sm font-medium text-foreground">Nom</label>
          <Input type="text" value={nom} disabled readOnly />
        </div>
      </div>

      <div className="space-y-2">
        <label className="block text-sm font-medium text-foreground">Téléphone</label>
        <Input type="tel" value={telephone} disabled readOnly />
      </div>

      <div className="space-y-2">
        <label className="block text-sm font-medium text-foreground">Zone de vente & détails du contrat</label>
        <Textarea
          rows={3}
          value={infoSupp}
          disabled
          readOnly
        />
      </div>

      <div className="space-y-3">
        <div className="flex items-center gap-2">
          <Building2 className="h-4 w-4 text-muted-foreground" />
          <h3 className="text-sm font-semibold text-foreground">Entreprise</h3>
        </div>

        <div className="rounded-xl border border-border p-4 space-y-4">
          <div className="flex items-center gap-3">
            {entreprise.logo_url ? (
              <img
                src={entreprise.logo_url}
                alt={entrepriseLogoAlt}
                className="h-12 w-12 rounded-lg object-cover border border-border bg-background"
              />
            ) : (
              <div className="h-12 w-12 rounded-lg bg-muted flex items-center justify-center">
                <Building2 className="h-5 w-5 text-muted-foreground" />
              </div>
            )}
            <p className="font-semibold text-foreground break-words">{entrepriseName}</p>
          </div>

          <dl className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <DetailRow label="NEQ / N° taxe" value={entreprise.tax_number} />
            <DetailRow label="E-mail" value={entreprise.email} />
            <DetailRow label="Téléphone" value={entreprise.phone} />
            <DetailRow label="Adresse" value={entreprise.address} />
          </dl>
        </div>
      </div>
    </div>
  )
}