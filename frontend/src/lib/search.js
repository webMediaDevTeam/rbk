/**
 * Seuil d'activation de la recherche « Grande liste » (docs/RULES.md §8),
 * commune aux listes **commerciale** (`/prospects`) et **admin**
 * (`/clients-historique`) :
 *
 *  - **3 caractères** minimum pour le texte libre (`Jean`, `LIC-777`,
 *    `Résidentiel`) — comme avant ;
 *  - **2 caractères** dès que la saisie n'est faite que de chiffres et de
 *    ponctuation de téléphone (`95`, `9500`, `+1`, `9900-0895-49`…) : un
 *    numéro se tape souvent au compte-goutte.
 *
 * Le rapprochement des formats (`9500301807` ↔ `(9500) 301-807` ↔
 * `9900-0895-49` ↔ `+…`) se fait côté serveur, dans
 * `Client::scopeSearchAll()` : la colonne `phone` et la saisie sont
 * ramenées aux chiffres seuls avant comparaison.
 *
 * @param {string} value  Contenu brut du champ de recherche.
 * @returns {boolean}  `true` si la requête doit être envoyée au serveur.
 */
export function isSearchActive(value) {
  const query = (value ?? '').trim()

  if (query.length >= 3) return true
  if (query.length < 2) return false

  return /^[\d\s()+.\/-]+$/.test(query)
}
