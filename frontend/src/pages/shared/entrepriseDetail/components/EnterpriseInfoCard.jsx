import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Building2, Eye, EyeOff, Loader2, RefreshCw } from 'lucide-react'
import Badge from '@/components/ui/badge.jsx'
import { getEntrepriseRingCentralCredentialsApi } from '@/api/admin.api.js'

function Field({ label, value }) {
  return (
    <div>
      <span className="text-muted-foreground text-xs">{label}</span>
      <p className="break-all text-sm text-foreground">{value ?? '—'}</p>
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
  const [credentialsVisible, setCredentialsVisible] = useState(false)
  const credentialsMutation = useMutation({
    mutationFn: () => getEntrepriseRingCentralCredentialsApi(entreprise?.id),
  })
  // Intercepteur axios : `mutation.data` = `{success, data}` → un seul `.data`.
  const credentials = credentialsMutation.data?.data

  if (!entreprise) return null

  const createdAt = entreprise.created_at
    ? new Date(entreprise.created_at).toLocaleDateString('fr-FR')
    : null

  const toggleCredentials = () => {
    if (credentialsVisible) {
      setCredentialsVisible(false)
      credentialsMutation.reset()
      return
    }

    setCredentialsVisible(true)
    credentialsMutation.mutate()
  }

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

      {ringCentralStatus?.active && (
        <section className="mt-5 space-y-4 border-t border-border pt-4">
          <h4 className="text-sm font-semibold text-foreground">Compte RingCentral</h4>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Field label="Identifiant du compte" value={ringCentralStatus.account?.id} />
            <Field label="Nom du compte" value={ringCentralStatus.account?.company_name} />
            <Field label="Numéro principal" value={ringCentralStatus.account?.main_number} />
            <Field label="État du compte" value={ringCentralStatus.account?.status} />
            <Field label="Type de compte" value={ringCentralStatus.account?.type} />
          </div>

          <div className="space-y-2">
            <h5 className="text-xs font-semibold text-foreground">
              Numéros RingCentral ({ringCentralStatus.phone_numbers?.length ?? 0})
            </h5>
            {!ringCentralStatus.phone_numbers_available ? (
              <p className="text-sm text-muted-foreground">Les numéros n’ont pas pu être chargés.</p>
            ) : ringCentralStatus.phone_numbers?.length ? (
              <ul className="divide-y divide-border rounded-md border border-border px-3">
                {ringCentralStatus.phone_numbers.map((number, index) => (
                  <li key={`${number.extension_id ?? 'account'}-${number.number}-${index}`} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                    <span className="font-medium text-foreground">{number.number}</span>
                    <span className="text-xs text-muted-foreground">
                      {[number.extension_number && `Poste ${number.extension_number}`, number.type, number.status]
                        .filter(Boolean)
                        .join(' · ') || 'Numéro du compte'}
                    </span>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-sm text-muted-foreground">Aucun numéro enregistré sur ce compte.</p>
            )}
          </div>
        </section>
      )}

      <section className="mt-5 space-y-3 border-t border-border pt-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h4 className="text-sm font-semibold text-foreground">Credentials RingCentral</h4>
            <p className="text-xs text-muted-foreground">Ces valeurs sont réservées aux administrateurs.</p>
          </div>
          <button
            type="button"
            onClick={toggleCredentials}
            disabled={credentialsMutation.isPending}
            className="inline-flex items-center gap-2 rounded-md border border-border px-3 py-1.5 text-sm text-foreground hover:bg-muted disabled:opacity-50"
          >
            {credentialsMutation.isPending ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : credentialsVisible ? (
              <EyeOff className="h-4 w-4" />
            ) : (
              <Eye className="h-4 w-4" />
            )}
            {credentialsVisible ? 'Masquer' : 'Afficher'}
          </button>
        </div>
        {credentialsMutation.isError && (
          <p className="text-sm text-destructive">Impossible de charger les credentials.</p>
        )}
        {credentialsVisible && credentials && (
          <div className="grid grid-cols-1 gap-3 rounded-md border border-border p-3 sm:grid-cols-2">
            <Field label="Client ID" value={credentials.client_id} />
            <Field label="Client secret" value={credentials.client_secret} />
            <div className="sm:col-span-2">
              <Field label="JWT" value={credentials.jwt} />
            </div>
          </div>
        )}
      </section>
    </div>
  )
}
