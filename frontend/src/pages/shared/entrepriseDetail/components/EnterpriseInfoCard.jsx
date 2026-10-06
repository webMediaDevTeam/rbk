import { Building2 } from 'lucide-react'
import Badge from '@/components/ui/badge.jsx'

function Field({ label, value }) {
  return (
    <div>
      <span className="text-muted-foreground text-xs">{label}</span>
      <p className="text-sm text-foreground">{value ?? '—'}</p>
    </div>
  )
}

/**
 * Onglet « Détails entreprise » — identité et coordonnées de la fiche
 * (`GET entreprises/{id}/stats` → `entreprise`).
 */
export default function EnterpriseInfoCard({ entreprise }) {
  if (!entreprise) return null

  const createdAt = entreprise.created_at
    ? new Date(entreprise.created_at).toLocaleDateString('fr-FR')
    : null

  return (
    <div className="rounded-xl bg-card text-card-foreground shadow-sm p-6">
      <div className="flex items-center gap-3 mb-5">
        <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary/10 text-primary">
          <Building2 className="h-5 w-5" />
        </div>
        <div className="min-w-0">
          <h3 className="text-sm font-semibold text-foreground truncate">{entreprise.name}</h3>
          <p className="text-xs text-muted-foreground">
            {entreprise.status === 'INACTIVE' ? (
              <Badge variant="destructive">Inactive</Badge>
            ) : (
              <Badge variant="success">Active</Badge>
            )}
          </p>
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <Field label="Adresse e-mail" value={entreprise.email} />
        <Field label="Téléphone" value={entreprise.phone} />
        <Field label="NIF / NEQ" value={entreprise.tax_number} />
        <Field label="Employés" value={entreprise.employees_count} />
        <Field label="Adresse" value={entreprise.address} />
        <Field label="Créée le" value={createdAt} />
      </div>
    </div>
  )
}
