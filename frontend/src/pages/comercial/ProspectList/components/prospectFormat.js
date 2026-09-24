/**
 * Formatage partagé des listes de prospects (tableau + cartes).
 * Les données peuvent venir de l'existant (objets) ou de n8n (chaînes).
 */

/** "Jean Dupont, Marie Curie" — accepte ["nom"] ou [{ name, role }]. */
export function respondentsText(client) {
  const list = Array.isArray(client?.respondents) ? client.respondents : []
  const names = list
    .map((r) => (typeof r === 'string' ? r : (r?.name ?? '')))
    .filter(Boolean)

  return names.length > 0 ? names.join(', ') : null
}

/** Libellés de catégories : `categories` d'abord, sinon `authorized_categories`. */
export function categoriesText(client) {
  const primary = Array.isArray(client?.categories) ? client.categories : []
  const source = primary.length > 0
    ? primary
    : (Array.isArray(client?.authorized_categories) ? client.authorized_categories : [])

  const labels = source
    .map((c) => (typeof c === 'string' ? c : (c?.label ?? c?.name ?? '')))
    .filter(Boolean)

  return labels.length > 0 ? labels.join(', ') : null
}
