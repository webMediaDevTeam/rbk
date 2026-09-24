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
  const respondentList = (Array.isArray(client.respondents) ? client.respondents : [])
    .map((r) => (typeof r === 'string' ? { name: r } : { name: r?.name ?? '', role: r?.role }))
    .filter((r) => r.name)

  const hasCategories = categoryList.length > 0
  const hasRespondents = respondentList.length > 0
  const hasActivity = client.reservations_count != null || client.notes_count != null

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
    respondentsCount: client.respondent_count || respondentList.length,
    hasCategories,
    hasRespondents,
    hasActivity,
  }
}
