export function useClientDetailsTab({ client }) {
  const licenceStartDate = client.licence_start_date ? new Date(client.licence_start_date).toLocaleDateString('fr-FR') : null
  const licenceEndDate = client.licence_end_date ? new Date(client.licence_end_date).toLocaleDateString('fr-FR') : null
  const suretyAmount = client.surety_amount != null
    ? new Intl.NumberFormat('fr-CA', { style: 'currency', currency: 'CAD' }).format(client.surety_amount)
    : null
  const licencePropre = client.licence_propre ? 'Oui' : 'Non'
  const hasCategories = !!(client.authorized_categories && client.authorized_categories.length > 0)
  const hasRespondents = !!(client.respondents && client.respondents.length > 0)
  const hasActivity = client.reservations_count != null || client.notes_count != null

  return {
    licenceStartDate,
    licenceEndDate,
    suretyAmount,
    licencePropre,
    hasCategories,
    hasRespondents,
    hasActivity,
  }
}