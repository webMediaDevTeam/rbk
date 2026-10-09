import { Building2, RefreshCw } from 'lucide-react'
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
export default function EnterpriseInfoCard({
  entreprise,
  ringCentralStatus,
  isCheckingRingCentral,
  ringCentralCheckFailed,
  onCheckRingCentral,
}) {
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
          <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
            {entreprise.status === 'INACTIVE' ? (
              <Badge variant="destructive">Inactive</Badge>
            ) : (
              <Badge variant="success">Active</Badge>
            )}
            <span>RingCentral</span>
            {isCheckingRingCentral ? (
              <Badge variant="secondary">Vérification…</Badge>
            ) : ringCentralCheckFailed ? (
              <Badge variant="destructive" title="La vérification RingCentral a échoué.">Inactif</Badge>
            ) : ringCentralStatus?.active ? (
              <Badge variant="success">Actif</Badge>
            ) : (
              <Badge variant="destructive" title={ringCentralStatus?.reason === 'credentials_missing'
                ? 'Credentials RingCentral absents en base.'
                : 'Authentification RingCentral non disponible.'}
              >
                Inactif
              </Badge>
            )}
            <button
              type="button"
              onClick={() => onCheckRingCentral?.()}
              disabled={isCheckingRingCentral}
              aria-label="Vérifier les credentials RingCentral"
              title="Vérifier les credentials RingCentral"
              className="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-50"
            >
              <RefreshCw className={`h-3.5 w-3.5 ${isCheckingRingCentral ? 'animate-spin' : ''}`} />
            </button>
          </div>
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
