export function useClientDetailsTab({ client }) {
  const licenceStartDate = client.licence_start_date ? new Date(client.licence_start_date).toLocaleDateString('fr-FR') : null
  const licenceEndDate = client.licence_end_date ? new Date(client.licence_end_date).toLocaleDateString('fr-FR') : null
  const suretyAmount = client.surety_amount != null
    ? new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' }).format(client.surety_amount)
    : null
  const licencePropre = client.licence_propre ? 'Oui' : 'Non'

  // Licence (propre) en ENTIER — colonne `licence_propre_numero` (payload n8n).
  const licencePropreNumero = client.licence_propre_numero ?? null

  // Cautionnement : tableau n8n `cautionnement_compagnie` ["nom1","nom2"],
  // repli sur l'ancien `surety_company` (string unique).
  const suretyCompanies = Array.isArray(client.cautionnement_compagnie)
    ? client.cautionnement_compagnie
    : (client.surety_company ? [client.surety_company] : [])
  const suretyCompanyList = suretyCompanies.join(' · ') || null

  // Catégories et sous-catégories : tableau de chaînes ("cat1, 1.2").
  const categoryList = (Array.isArray(client.authorized_categories) ? client.authorized_categories : [])
    .map((c) => (typeof c === 'string' ? c : (c?.label ?? c?.name ?? '')))
    .filter(Boolean)

  // Répondants : tableau de chaînes (n8n) ou d'objets { name, role } (ancien format).
  const allRespondents = (Array.isArray(client.respondents) ? client.respondents : [])
    .map((r) => (typeof r === 'string' ? { name: r } : { name: r?.name ?? '', role: r?.role }))
    .filter((r) => r.name)

  // Le représentant est un répondant comme un autre : on l'affiche UNE seule
  // fois, dans son propre bloc « Représentant ». « Répondants » ne liste donc
  // que les AUTRES répondants (et disparaît si le représentant était le seul).
  //
  // Un répondant est reconnu comme représentant si son nom correspond à
  // `representative_name`, ou si son rôle est explicitement « Représentant »
  // (ancien format `{ name, role }`). La comparaison du nom ignore la casse,
  // les accents et la ponctuation (« L'Oratoire, Saint-Joseph » =
  // « l oratoire saint joseph »).
  const normalizeName = (value) =>
    (value ?? '')
      .toString()
      .normalize('NFD')
      .replace(/[̀-ͯ]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, ' ')
      .trim()

  const representativeKey = normalizeName(client.representative_name)
  const isRepresentative = (r) =>
    (representativeKey !== '' && normalizeName(r.name) === representativeKey)
    || normalizeName(r.role) === 'representant'

  const respondentList = allRespondents.filter((r) => !isRepresentative(r))
  const representative = allRespondents.find((r) => isRepresentative(r)) ?? null

  // `representative_name` peut être vide en base alors que le représentant est
  // identifié par son rôle dans `respondents` : on affiche alors le nom réel
  // plutôt qu'un tiret.
  const representativeName = client.representative_name || representative?.name || null

  const hasCategories = categoryList.length > 0
  const hasRespondents = respondentList.length > 0
  const hasRepresentative = !!representativeName
  const hasActivity = client.reservations_count != null || client.notes_count != null

  // Le numéro n'est envoyé par l'API qu'aux admins et au commercial qui détient
  // la réservation en cours du client : son absence = droit de regard absent.
  const hasPhone = !!client.phone

  return {
    licenceStartDate,
    licenceEndDate,
    suretyAmount,
    licencePropre,
    licencePropreNumero,
    suretyCompanies,
    suretyCompanyList,
    categoryList,
    respondentList,
    // Le compteur porte sur les répondants **affichés** : le représentant
    // ayant son propre bloc, il n'est pas compté deux fois.
    respondentsCount: respondentList.length,
    representativeName,
    hasCategories,
    hasRespondents,
    hasRepresentative,
    hasActivity,
    hasPhone,
  }
}
