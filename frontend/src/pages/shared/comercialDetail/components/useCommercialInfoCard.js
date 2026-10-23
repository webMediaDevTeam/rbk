/** Formatte une date (ou `null` → `null`, rendu « — » par la carte). */
const fmtDate = (value) => (value ? new Date(value).toLocaleDateString() : null)

/** Formatte une date à l'heure — échéances et synchronisations. */
const fmtDateTime = (value) => (value ? new Date(value).toLocaleString() : null)

/**
 * Données de la fiche « Détails employé » — **tout** ce que `show()` livre, en
 * valeurs prêtes à afficher.
 *
 * Deux sources se complètent :
 *  - `commercial` = la projection du compte (`users` + `profil` = fiche
 *    `employees`), même forme que les lignes de la page « Employés » — c'est
 *    elle qui porte les réglages éditables par la modale « Modifier » ;
 *  - `employee` = la projection historique de la fiche, dont quelques libellés
 *    (`cree_le`, `additional_info`).
 *
 * Les privilèges sont lus à l'**état effectif** renvoyé par le serveur :
 * `gps_bypass` vaut ce que le géorepérage acceptera à la prochaine connexion
 * (dérogation posée **et** non expirée), pas la colonne brute — la fiche ne peut
 * donc pas promettre un accès que le serveur refuserait.
 */
export function useCommercialInfoCard({ commercial, employee, entreprise, name, email, phone, avatarUrl, companyName }) {
  const profil = commercial?.profil ?? null

  const initials = name.slice(0, 2).toUpperCase()
  const role = commercial?.role ?? employee?.role ?? 'COMERCIAL'
  const accountStatus = commercial?.status ?? employee?.status ?? '—'
  const additionalInfo = employee?.additional_info ?? profil?.info_supp ?? null
  const inscriptionDate = fmtDate(commercial?.created_at ?? employee?.cree_le)
  const emailVerifiedAt = fmtDateTime(commercial?.email_verified_at)
  // Dernier e-mail d'activation envoyé : avec `email_verified_at` vide, dit que
  // le compte n'a pas encore choisi son mot de passe (lien toujours en attente).
  const verificationSentAt = fmtDateTime(commercial?.verification_sent_at)
  // Login court du compte : `null` = compte créé avant la colonne
  // `users.username` (l'affichage le dit plutôt que d'inventer une valeur).
  const username = commercial?.username ?? null
  const hasPermission = Boolean(commercial?.has_permission)
  const gpsBypass = Boolean(commercial?.gps_bypass)
  const gpsBypassExpiresAt = fmtDateTime(commercial?.gps_bypass_expires_at)

  // Téléphonie : l'appareil et le numéro choisis dans le compte RingCentral de
  // l'entreprise (+ le poste, déduit de l'appareil). La projection `employee`
  // de la fiche fait foi — elle porte aussi les colonnes que la modale n'écrit
  // pas (numéro d'extension, liste des numéros du poste) ; `profil` sert de
  // repli pour les valeurs partagées avec la page « Employés ».
  const ringcentral = {
    device: employee?.ringcentral_device_id ?? profil?.ringcentral_device_id ?? null,
    extension: employee?.ringcentral_extension_id ?? profil?.ringcentral_extension_id ?? null,
    extensionNumber: employee?.ringcentral_extension_number ?? null,
    fromNumber: employee?.ringcentral_from_number ?? profil?.ringcentral_from_number ?? null,
    // Tous les numéros assignés au poste, posés par la synchro (`array`).
    phoneNumbers: employee?.ringcentral_phone_numbers ?? [],
    syncedAt: fmtDateTime(employee?.ringcentral_synced_at ?? profil?.ringcentral_synced_at),
  }

  // Entreprise : la fiche `entreprise` de la réponse fait foi ; `profil.entreprise`
  // (forme des lignes de la liste) sert de repli.
  const entrepriseSource = entreprise ?? profil?.entreprise ?? null
  const entrepriseStatus = entrepriseSource?.status ?? '—'
  const entrepriseEmail = entrepriseSource?.email ?? '—'
  const entreprisePhone = entrepriseSource?.phone ?? '—'
  const entrepriseAddress = entrepriseSource?.address ?? '—'
  const entrepriseTaxNumber = entrepriseSource?.tax_number ?? '—'
  const entrepriseSourceLabel = entrepriseSource?.source ?? null
  const entrepriseLogoUrl = entrepriseSource?.logo_url ?? null

  return {
    entreprise,
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
  }
}
