import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client.js'

/**
 * Compteurs de `GET clients/overview` — servent à la barre de filtres
 * « Tous + 9 valeurs affichées » (10 badges, §9) des listes (Grande liste — panels
 * commercial et admin, À rappeler, BV). La colonne « Statut » lit
 * `by_display_status` (§9 de docs/RULES.md) ; `by_status` reste exposé par l'API.
 *
 * Pas de cache long : le serveur recalcule à chaque appel (les compteurs
 * bougent à chaque réservation / issue d'appel), on rafraîchit côté client
 * toutes les 15 s et au changement de fenêtre.
 *
 * @param {object|undefined|null} counts compteurs fournis **par la page**
 *                             (détail d'un employé : périmètre restreint à ses
 *                             appels) — la requête globale est alors
 *                             désactivée. `undefined` = compteurs globaux,
 *                             `null` = chargement en cours.
 * @param {object}  [params]    paramètres du périmètre serveur :
 *                             - `filters` : périmètre courant de la Grande
 *                               liste admin (`search`, `municipality`,
 *                               `category`, `administrative_region`,
 *                               `employee_id`, `source`). Chaque valeur
 *                               vide/absente = aucun filtre ; les compteurs
 *                               suivent alors le **même** périmètre que les
 *                               lignes rendues (invariant « compteur = lignes »,
 *                               §9). Changer un filtre change la `queryKey` →
 *                               les badges se recalculent. **`enterprise_id`
 *                               n'est jamais transmis** : « Entreprise » ne
 *                               porte que la liste déroulante « Employé ».
 *                             - `source` : raccourci historique (équivaut à
 *                               `filters.source`), conservé par compatibilité.
 *                             - `year` / `month` / `week` / `day` : période
 *                               du tableau de bord (tuiles « Grande liste »).
 *                               Posée, elle ne borne **que** les tuiles d'issue
 *                               Oui → Blacklist (sur la date de la note du
 *                               client) ; « Tous » / « Libre » / « Sans tel. »
 *                               restent fixes. Absente = instantané complet
 *                               (comportement des badges de liste).
 */
export function useProspectKpis(counts = undefined, params = {}) {
  const localCounts = counts !== undefined

  // Normalise le périmètre : uniquement les valeurs non vides, dans un ordre
  // stable pour la `queryKey`. `source` explicite écrase `filters.source`.
  const filters = params.filters ?? {}
  // Fenêtre roulante (`range`) : elle **prime** sur la cascade calendaire —
  // posée, on omet `year`/`month`/`week`/`day` (le serveur les ignore de toute
  // façon, et les garder fausserait la `queryKey`).
  const hasRange = Boolean(filters.range)
  const scoped = {
    search: params.search || filters.search || undefined,
    municipality: filters.municipality || undefined,
    category: filters.category || undefined,
    administrative_region: filters.administrative_region || undefined,
    employee_id: filters.employee_id || undefined,
    source: params.source || filters.source || undefined,
    // Période du dashboard : chaque réglage change la `queryKey` → les tuiles
    // se recalculent **immédiatement** (nouvelle clé = pas de cache).
    range: filters.range || undefined,
    year: hasRange ? undefined : (filters.year || undefined),
    month: hasRange ? undefined : (filters.month || undefined),
    week: hasRange ? undefined : (filters.week || undefined),
    day: hasRange ? undefined : (filters.day || undefined),
  }
  const active = Object.fromEntries(Object.entries(scoped).filter(([, value]) => value !== undefined))
  const hasScope = Object.keys(active).length > 0

  const { data, isLoading, isError } = useQuery({
    queryKey: ['prospect-kpis', active],
    queryFn: () => api.get('/clients/overview', hasScope ? { params: active } : {}),
    staleTime: 1000 * 15,
    retry: false,
    enabled: !localCounts,
  })

  if (localCounts) {
    return { kpis: counts, isLoading: !counts, isError: false }
  }

  return { kpis: data?.data ?? null, isLoading, isError }
}
