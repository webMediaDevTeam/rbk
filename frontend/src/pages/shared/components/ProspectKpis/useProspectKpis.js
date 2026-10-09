import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client.js'

/**
 * Compteurs de `GET clients/overview` — servent à la barre de filtres
 * « Tous + 9 valeurs affichées » (10 badges, §9) des listes (Grande liste — panels commercial
 * et admin, À rappeler, BV). La colonne « Statut » lit `by_display_status`
 * (§9 de docs/RULES.md) ; `by_status` reste exposé par l'API.
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
 * @param {object}  [params]    paramètres du périmètre serveur : **`source`**
 *                             (onglet actif de la Grande liste admin) borne
 *                             tous les compteurs à cette origine, pour que
 *                             l'invariant « compteur du badge = lignes
 *                             rendues » tienne sous chaque onglet
 *                             (`GET clients/overview?source=`). Absente =
 *                             périmètre entier.
 */
export function useProspectKpis(counts = undefined, params = {}) {
  const localCounts = counts !== undefined
  const source = params.source || undefined

  const { data, isLoading, isError } = useQuery({
    queryKey: ['prospect-kpis', { source }],
    queryFn: () => api.get('/clients/overview', source ? { params: { source } } : {}),
    staleTime: 1000 * 15,
    retry: false,
    enabled: !localCounts,
  })

  if (localCounts) {
    return { kpis: counts, isLoading: !counts, isError: false }
  }

  return { kpis: data?.data ?? null, isLoading, isError }
}
