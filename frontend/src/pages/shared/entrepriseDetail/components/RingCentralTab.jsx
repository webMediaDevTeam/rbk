import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, Eye, EyeOff, KeyRound, Loader2, Pencil, RefreshCw, Smartphone, Users, X } from 'lucide-react'
import {
  checkEntrepriseRingCentralApi,
  getEntrepriseRingCentralCredentialsApi,
  getEntrepriseRingCentralTokenApi,
  updateEntrepriseRingCentralCredentialsApi,
} from '@/api/admin.api.js'

function Field({ label, value }) {
  return (
    <div>
      <span className="text-muted-foreground text-xs">{label}</span>
      <p className="break-all text-sm text-foreground">{value ?? '—'}</p>
    </div>
  )
}

/** Erreur serveur d'un appel RingCentral : message + indice (`error.hint`). */
function ErrorBox({ title, response }) {
  if (!response) return null

  const message = response.message ?? 'Erreur inconnue.'
  const hint = response.error?.hint

  return (
    <div className="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm">
      <p className="font-medium text-destructive">{title}</p>
      <p className="mt-1 break-all text-destructive/90">{message}</p>
      {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
    </div>
  )
}

/**
 * Onglet « RingCentral » de la fiche entreprise (`/entreprises/:id`) —
 * ADMIN / SUPER_ADMIN. Triple affichage, dans l'ordre du flux :
 *
 *  1. **Identifiants** (`GET entreprises/{id}/ringcentral-credentials`) —
 *     client ID / secret / JWT stockés en base, révélés sur demande ;
 *  2. **Access token** (`GET entreprises/{id}/ringcentral-token`) —
 *     échange JWT → access_token côté serveur ; la valeur renvoyée est
 *     masquée (extrémités seules) avec type + expiration ;
 *  3. **Compte** (`GET entreprises/{id}/ringcentral-status`) — appelé
 *     automatiquement après un token valide (jeton réutilisé, aucun nouvel
 *     échange) : résumé du compte, numéros et postes.
 */
export default function RingCentralTab({ id, ringCentralConfigured = false }) {
  const [credentialsVisible, setCredentialsVisible] = useState(false)

  const {
    data: credentialsResponse,
    isLoading: isLoadingCredentials,
    isError: credentialsFailed,
    refetch: refetchCredentials,
  } = useQuery({
    queryKey: ['enterprise-ringcentral-credentials', id],
    queryFn: () => getEntrepriseRingCentralCredentialsApi(id),
    enabled: !!id && credentialsVisible,
    staleTime: 0,
    retry: false,
  })
  // L'intercepteur axios renvoie déjà l'enveloppe `{success, data}` :
  // UNE seule couche `data` reste à déballer (pas `.data?.data`).
  const credentials = credentialsResponse?.data

  // Correction des identifiants en base (`PUT …/ringcentral-credentials`).
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState(false)
  const [form, setForm] = useState({
    ringcentral_client_id: '',
    ringcentral_client_secret: '',
    ringcentral_token: '',
  })
  const updateMutation = useMutation({
    mutationFn: () => updateEntrepriseRingCentralCredentialsApi(id, form),
  })
  const updateError = updateMutation.error?.response?.data
  const updateErrorMessage =
    Object.values(updateError?.errors ?? {})[0]?.[0] ?? updateError?.message ?? null

  const startEditing = () => {
    setForm({
      ringcentral_client_id: credentials?.client_id ?? '',
      ringcentral_client_secret: credentials?.client_secret ?? '',
      ringcentral_token: credentials?.jwt ?? '',
    })
    setEditing(true)
  }

  const cancelEditing = () => {
    setEditing(false)
    updateMutation.reset()
  }

  const handleSaveCredentials = () => {
    updateMutation.mutate(undefined, {
      onSuccess: async () => {
        setEditing(false)
        // Identifiants corrigés : jeton / compte affichés deviennent périmés.
        tokenMutation.reset()
        accountMutation.reset()
        await refetchCredentials()
        await queryClient.invalidateQueries({ queryKey: ['entreprise-stats', id] })
      },
    })
  }

  const setFormField = (key) => (event) => setForm((prev) => ({ ...prev, [key]: event.target.value }))

  // 2. JWT → access_token (le serveur persiste le jeton : réutilisé ensuite).
  const tokenMutation = useMutation({
    mutationFn: () => getEntrepriseRingCentralTokenApi(id),
  })
  const token = tokenMutation.data?.data
  const tokenError = tokenMutation.error?.response?.data

  // 3. Compte / numéros / postes — suit le token (auto) ou se rafraîchit seul.
  const accountMutation = useMutation({
    mutationFn: () => checkEntrepriseRingCentralApi(id),
  })
  const accountStatus = accountMutation.data?.data
  const accountError = accountMutation.error?.response?.data

  const handleGetToken = () => {
    tokenMutation.mutate(undefined, {
      // Jeton obtenu → on affiche immédiatement le compte qu'il ouvre.
      onSuccess: () => accountMutation.mutate(),
    })
  }

  const toggleCredentials = () => {
    if (credentialsVisible) {
      setCredentialsVisible(false)
      return
    }

    setCredentialsVisible(true)
    refetchCredentials()
  }

  const expiresAtLabel = token?.expire_time
    ? new Date(token.expire_time).toLocaleString('fr-FR')
    : null
  const expiresInMinutes = token?.expires_in
    ? Math.max(1, Math.round(token.expires_in / 60))
    : null

  return (
    <div className="rounded-xl bg-card text-card-foreground shadow-sm p-6 space-y-6">
      {/* 1. Identifiants en base */}
      <section className="space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 className="flex items-center gap-2 text-sm font-semibold text-foreground">
              <KeyRound className="h-4 w-4 text-primary" /> Identifiants RingCentral
            </h3>
            <p className="mt-1 text-xs text-muted-foreground">
              Valeurs enregistrées sur l’entreprise (base de données) — réservées aux administrateurs.
            </p>
          </div>
          <div className="inline-flex items-center gap-2">
            {credentialsVisible && credentials && !editing && (
              <button
                type="button"
                onClick={startEditing}
                className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:bg-primary/90"
              >
                <Pencil className="h-4 w-4" /> Modifier
              </button>
            )}
            {!editing && (
              <button
                type="button"
                onClick={toggleCredentials}
                disabled={isLoadingCredentials}
                className="inline-flex items-center gap-2 rounded-md border border-border px-3 py-1.5 text-sm text-foreground hover:bg-muted disabled:opacity-50"
              >
                {isLoadingCredentials ? (
                  <Loader2 className="h-4 w-4 animate-spin" />
                ) : credentialsVisible ? (
                  <EyeOff className="h-4 w-4" />
                ) : (
                  <Eye className="h-4 w-4" />
                )}
                {credentialsVisible ? 'Masquer' : 'Afficher'}
              </button>
            )}
          </div>
        </div>

        {!ringCentralConfigured && (
          <p className="rounded-md border border-border bg-muted/40 p-3 text-sm text-muted-foreground">
            Aucun compte RingCentral complet n’est enregistré sur cette entreprise (client ID, secret et
            JWT requis) — ajoutez-les via « Modifier une entreprise ».
          </p>
        )}

        {credentialsFailed && (
          <p className="text-sm text-destructive">Impossible de charger les credentials.</p>
        )}

        {updateMutation.isSuccess && !editing && (
          <p className="text-sm text-muted-foreground">
            {updateMutation.data?.message ?? 'Identifiants RingCentral enregistrés.'}
          </p>
        )}

        {credentialsVisible && credentials && !editing && (
          <div className="grid grid-cols-1 gap-3 rounded-md border border-border p-3 sm:grid-cols-2">
            <Field label="Client ID" value={credentials.client_id} />
            <Field label="Client secret" value={credentials.client_secret} />
            <div className="sm:col-span-2">
              <Field label="JWT" value={credentials.jwt} />
            </div>
          </div>
        )}

        {/* Correction des identifiants en base : clé vidée = valeur retirée,
            clé remplie = remplacée ; après enregistrement, « Obtenir le
            token » teste la nouvelle paire. */}
        {editing && (
          <div className="space-y-3 rounded-md border border-border p-3">
            <p className="text-xs text-muted-foreground">
              Champ vidé = valeur retirée en base ; champ rempli = remplacé. Pensez à régénérer le
              JWT depuis l’application RingCentral qui possède ce client ID.
            </p>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <label className="space-y-1">
                <span className="text-muted-foreground text-xs">Client ID</span>
                <input
                  type="text"
                  value={form.ringcentral_client_id}
                  onChange={setFormField('ringcentral_client_id')}
                  className="w-full rounded-md border border-border bg-background px-3 py-1.5 text-sm text-foreground"
                />
              </label>
              <label className="space-y-1">
                <span className="text-muted-foreground text-xs">Client secret</span>
                <input
                  type="text"
                  value={form.ringcentral_client_secret}
                  onChange={setFormField('ringcentral_client_secret')}
                  className="w-full rounded-md border border-border bg-background px-3 py-1.5 text-sm text-foreground"
                />
              </label>
              <label className="space-y-1 sm:col-span-2">
                <span className="text-muted-foreground text-xs">JWT</span>
                <textarea
                  rows={3}
                  value={form.ringcentral_token}
                  onChange={setFormField('ringcentral_token')}
                  className="w-full break-all rounded-md border border-border bg-background px-3 py-1.5 text-sm text-foreground"
                />
              </label>
            </div>

            {updateErrorMessage && (
              <p className="text-sm text-destructive">{updateErrorMessage}</p>
            )}

            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={handleSaveCredentials}
                disabled={updateMutation.isPending}
                className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
              >
                {updateMutation.isPending ? (
                  <Loader2 className="h-4 w-4 animate-spin" />
                ) : (
                  <Pencil className="h-4 w-4" />
                )}
                Enregistrer
              </button>
              <button
                type="button"
                onClick={cancelEditing}
                disabled={updateMutation.isPending}
                className="inline-flex items-center gap-2 rounded-md border border-border px-3 py-1.5 text-sm text-foreground hover:bg-muted disabled:opacity-50"
              >
                <X className="h-4 w-4" /> Annuler
              </button>
            </div>
          </div>
        )}
      </section>

      {/* 2. Access token (JWT → /oauth/token) */}
      <section className="space-y-3 border-t border-border pt-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 className="flex items-center gap-2 text-sm font-semibold text-foreground">
              <RefreshCw className="h-4 w-4 text-primary" /> Access token
            </h3>
            <p className="mt-1 text-xs text-muted-foreground">
              Échange du JWT contre un jeton OAuth (grant <code>jwt-bearer</code>) — réutilisé côté
              serveur jusqu’à expiration.
            </p>
          </div>
          <button
            type="button"
            onClick={handleGetToken}
            disabled={tokenMutation.isPending || !ringCentralConfigured}
            className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
          >
            {tokenMutation.isPending ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
              <KeyRound className="h-4 w-4" />
            )}
            {token ? 'Rafraîchir le token' : 'Obtenir le token'}
          </button>
        </div>

        {tokenError && <ErrorBox title="Échange JWT refusé." response={tokenError} />}

        {token && (
          <div className="grid grid-cols-1 gap-3 rounded-md border border-border p-3 sm:grid-cols-2">
            <div className="sm:col-span-2">
              <Field label="Access token (masqué)" value={token.access_token} />
            </div>
            <Field label="Type" value={token.token_type} />
            <Field
              label="Expire dans"
              value={expiresInMinutes ? `≈ ${expiresInMinutes} min` : null}
            />
            <div className="sm:col-span-2">
              <Field label="Expire le" value={expiresAtLabel} />
            </div>
          </div>
        )}
      </section>

      {/* 3. Compte RingCentral ouvert par ce token */}
      <section className="space-y-3 border-t border-border pt-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 className="flex items-center gap-2 text-sm font-semibold text-foreground">
              <Building2 className="h-4 w-4 text-primary" /> Compte RingCentral
            </h3>
            <p className="mt-1 text-xs text-muted-foreground">
              Compte, numéros et postes — chargés avec le token ci-dessus.
            </p>
          </div>
          <button
            type="button"
            onClick={() => accountMutation.mutate()}
            disabled={accountMutation.isPending || !ringCentralConfigured}
            className="inline-flex items-center gap-2 rounded-md border border-border px-3 py-1.5 text-sm text-foreground hover:bg-muted disabled:opacity-50"
          >
            {accountMutation.isPending ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
              <RefreshCw className="h-4 w-4" />
            )}
            Rafraîchir
          </button>
        </div>

        {accountError && <ErrorBox title="Lecture du compte impossible." response={accountError} />}

        {accountStatus && !accountStatus.active && (
          <p className="text-sm text-muted-foreground">
            {accountStatus.reason === 'credentials_missing'
              ? 'Credentials RingCentral absents en base.'
              : 'Authentification RingCentral non disponible.'}
          </p>
        )}

        {accountStatus?.active && (
          <>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <Field label="Identifiant du compte" value={accountStatus.account?.id} />
              <Field label="Nom du compte" value={accountStatus.account?.company_name} />
              <Field label="Numéro principal" value={accountStatus.account?.main_number} />
              <Field label="État du compte" value={accountStatus.account?.status} />
              <Field label="Type de compte" value={accountStatus.account?.type} />
            </div>

            <div className="space-y-2">
              <h5 className="flex items-center gap-1.5 text-xs font-semibold text-foreground">
                <Smartphone className="h-3.5 w-3.5" /> Numéros RingCentral ({accountStatus.phone_numbers?.length ?? 0})
              </h5>
              {!accountStatus.phone_numbers_available ? (
                <p className="text-sm text-muted-foreground">Les numéros n’ont pas pu être chargés.</p>
              ) : accountStatus.phone_numbers?.length ? (
                <ul className="divide-y divide-border rounded-md border border-border px-3">
                  {accountStatus.phone_numbers.map((number, index) => (
                    <li
                      key={`${number.extension_id ?? 'account'}-${number.number}-${index}`}
                      className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"
                    >
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

            <div className="space-y-2">
              <h5 className="flex items-center gap-1.5 text-xs font-semibold text-foreground">
                <Users className="h-3.5 w-3.5" /> Postes / extensions ({accountStatus.extensions?.length ?? 0})
              </h5>
              {!accountStatus.extensions_available ? (
                <p className="text-sm text-muted-foreground">Les postes n’ont pas pu être chargés.</p>
              ) : accountStatus.extensions?.length ? (
                <ul className="divide-y divide-border rounded-md border border-border px-3">
                  {accountStatus.extensions.map((extension, index) => (
                    <li
                      key={`${extension.id ?? 'ext'}-${index}`}
                      className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"
                    >
                      <span className="font-medium text-foreground">
                        {extension.name || `Poste ${extension.extension_number ?? '—'}`}
                      </span>
                      <span className="text-xs text-muted-foreground">
                        {[extension.extension_number && `Poste ${extension.extension_number}`, extension.status]
                          .filter(Boolean)
                          .join(' · ')}
                      </span>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-sm text-muted-foreground">Aucun poste enregistré sur ce compte.</p>
              )}
            </div>
          </>
        )}
      </section>
    </div>
  )
}
