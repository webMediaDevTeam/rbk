import { Building2, Mail, Phone } from 'lucide-react'
import { useCommercialInfoCard } from './useCommercialInfoCard.js'
import { ROLE_LABELS } from '@/pages/shared/profil/components/SettingsSidebar.jsx'

/**
 * Ligne « libellé / valeur » d'une fiche en lecture. `mono` pour les identifiants
 * techniques (appareil / poste RingCentral), `full` pour les valeurs qui ont
 * besoin des deux colonnes (adresse, notes).
 */
function Field({ label, value, mono = false, full = false }) {
  return (
    <div className={full ? 'sm:col-span-2' : ''}>
      <dt className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{label}</dt>
      {/* `break-words` par défaut : une valeur longue (adresse complète,
          identifiant RingCentral) ne doit pas déborder de la carte. */}
      <dd className={`mt-0.5 text-sm break-words text-foreground ${mono ? 'font-mono text-xs' : ''}`}>
        {value || '—'}
      </dd>
    </div>
  )
}

/** Pastille binaire — vert tendre quand le réglage est actif, neutre sinon. */
function Pill({ on, children }) {
  return (
    <span
      className={`inline-flex items-center rounded px-2 py-0.5 text-xs font-semibold ${
        on ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-muted text-muted-foreground'
      }`}
    >
      {children}
    </span>
  )
}

/** Titre de section d'une carte de fiche. */
function SectionTitle({ children }) {
  return <div className="text-sm font-semibold text-muted-foreground">{children}</div>
}

/**
 * Onglet « Détails employé » de la fiche — **toutes** les données du compte :
 * identité (e-mail, login, téléphone, rôle, statut), réglages (privilège
 * commercial, dérogation hors zones et son échéance), téléphonie RingCentral
 * (appareil, poste, numéro source, dernière synchro), notes et dates de vie du
 * compte, puis l'entreprise de rattachement.
 *
 * La carte est en **lecture seule** : l'édition passe par le bouton « Modifier »
 * de l'en-tête de page (`CommercialUpdateModal`), qui présente ces mêmes champs
 * sous forme de formulaire contrôlé — d'où la projection `user.profil` partagée
 * avec la page « Employés » côté serveur.
 */
export default function CommercialInfoCard(props) {
  const {
    name,
    email,
    phone,
    avatarUrl,
    companyName,
    initials,
    role,
    accountStatus,
    additionalInfo,
    inscriptionDate,
    emailVerifiedAt,
    verificationSentAt,
    username,
    hasPermission,
    gpsBypass,
    gpsBypassExpiresAt,
    ringcentral,
    entrepriseSource,
    entrepriseStatus,
    entrepriseEmail,
    entreprisePhone,
    entrepriseAddress,
    entrepriseTaxNumber,
    entrepriseSourceLabel,
    entrepriseLogoUrl,
  } = useCommercialInfoCard(props)

  return (
    <div className="grid grid-cols-1 gap-6">
      <div className="rounded-xl bg-card text-card-foreground shadow-sm p-6 space-y-6">
        {/* ── Employé : identité + réglages du compte ── */}
        <div className="space-y-4">
          <SectionTitle>Employé</SectionTitle>
          <div className="flex items-center gap-3">
            {avatarUrl ? (
              <img src={avatarUrl} alt={name} className="h-12 w-12 rounded-full object-cover" />
            ) : (
              <div className="h-12 w-12 rounded-full bg-muted flex items-center justify-center text-sm font-bold">
                {initials}
              </div>
            )}
            <div>
              <div className="text-lg font-semibold">{name}</div>
              <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                <span className="inline-flex items-center rounded bg-muted px-2 py-0.5 text-[11px] font-bold uppercase">
                  {ROLE_LABELS[role] ?? role}
                </span>
                <Pill on={accountStatus === 'ACTIVE'}>{accountStatus}</Pill>
              </div>
            </div>
          </div>

          <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4 text-sm">
            <div className="flex items-center gap-2">
              <Mail className="h-4 w-4 text-muted-foreground shrink-0" />
              <Field label="Adresse e-mail" value={email} />
            </div>
            <div className="flex items-center gap-2">
              <Phone className="h-4 w-4 text-muted-foreground shrink-0" />
              <Field label="Téléphone" value={phone} />
            </div>
            {/* Login court : `null` = compte antérieur à la colonne, on le dit. */}
            <Field label="Login" value={username ?? 'non défini'} />
            <Field label="Rôle" value={ROLE_LABELS[role] ?? role} />
            <Field
              label="Privilège commercial"
              value={
                <Pill on={hasPermission}>
                  {hasPermission ? 'Peut libérer sa liste' : 'Libération refusée'}
                </Pill>
              }
            />
            <Field
              label="Connexion hors zones (24 h)"
              value={
                <span className="flex flex-wrap items-center gap-2">
                  <Pill on={gpsBypass}>{gpsBypass ? 'Active' : 'Inactive'}</Pill>
                  {gpsBypass && gpsBypassExpiresAt && (
                    <span className="text-xs text-muted-foreground">jusqu&apos;au {gpsBypassExpiresAt}</span>
                  )}
                </span>
              }
            />
            <Field label="Inscrit le" value={inscriptionDate} />
            <Field label="E-mail vérifié le" value={emailVerifiedAt} />
            {/* Les deux dates ci-dessus se lisent ensemble : « envoyée » sans
                « vérifié » = le compte n'a pas encore choisi son mot de passe. */}
            <Field label="Activation envoyée le" value={verificationSentAt} />
            <Field label="Informations complémentaires" value={additionalInfo} full />
          </dl>
        </div>

        <div className="border-t border-border" />

        {/* ── Téléphonie : ce que la modale choisit dans le compte RingCentral
            de l'entreprise, plus ce que la synchro y a résolu (extension,
            numéros du poste) ── */}
        <div className="space-y-4">
          <SectionTitle>Téléphonie — RingCentral</SectionTitle>
          <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4 text-sm">
            <Field label="Entreprise" value={companyName} />
            <Field label="Numéro source (appel sortant)" value={ringcentral.fromNumber} mono />
            <Field label="Appareil" value={ringcentral.device} mono />
            <Field label="Poste (identifiant)" value={ringcentral.extension} mono />
            <Field label="Poste (numéro d'extension)" value={ringcentral.extensionNumber} mono />
            <Field label="Synchronisé le" value={ringcentral.syncedAt} />
            <Field
              label="Numéros assignés au poste"
              value={
                ringcentral.phoneNumbers.length > 0 ? (
                  <span className="flex flex-wrap gap-1.5">
                    {ringcentral.phoneNumbers.map((number) => (
                      <span
                        key={number}
                        className="rounded bg-muted px-2 py-0.5 font-mono text-xs text-foreground"
                      >
                        {number}
                      </span>
                    ))}
                  </span>
                ) : null
              }
              full
            />
          </dl>
          <p className="text-xs text-muted-foreground">
            Le poste est déduit de l&apos;appareil ; la liste des appareils provient du
            compte RingCentral de l&apos;entreprise.
          </p>
        </div>

        <div className="border-t border-border" />

        {/* ── Entreprise de rattachement ── */}
        <div className="space-y-4">
          <SectionTitle>Entreprise</SectionTitle>
          {entrepriseSource ? (
            <>
              <div className="flex items-center gap-3">
                {entrepriseLogoUrl ? (
                  <img src={entrepriseLogoUrl} alt={companyName} className="h-12 w-12 rounded-lg object-cover" />
                ) : (
                  <div className="h-12 w-12 rounded-lg bg-muted flex items-center justify-center">
                    <Building2 className="h-6 w-6 text-muted-foreground" />
                  </div>
                )}
                <div>
                  <div className="text-lg font-semibold">{companyName}</div>
                  <div className="text-sm text-muted-foreground">{entrepriseStatus}</div>
                </div>
              </div>
              <dl className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4 text-sm">
                <Field label="Source" value={entrepriseSourceLabel} />
                <Field label="Statut" value={entrepriseStatus} />
                <Field label="Adresse e-mail" value={entrepriseEmail} />
                <Field label="Téléphone" value={entreprisePhone} />
                <Field label="N° fiscal" value={entrepriseTaxNumber} />
                <Field label="Adresse" value={entrepriseAddress} full />
              </dl>
            </>
          ) : (
            <div className="text-sm text-muted-foreground">Aucune entreprise associée.</div>
          )}
        </div>
      </div>
    </div>
  )
}
