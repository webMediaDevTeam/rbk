import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client.js'

/**
 * Options des filtres « Entreprise » / « Employé » de la Grande liste admin.
 *
 * Le serveur ne renvoie que les commerciaux ayant **interagi au moins une
 * fois** avec un client (auteurs d'≥ 1 note) et les entreprises porteuses d'un
 * tel employé. Chaque employé porte son `enterprise_id` : on filtre la liste
 * d'employés côté client selon l'entreprise choisie (aucun aller-retour
 * supplémentaire quand l'entreprise change).
 *
 * Résultat mis en cache 5 min : ces périmètres changent peu, mais plus
 * souvent que les distincts géographiques (1 semaine).
 *
 * `source` (onglet « source » actif) restreint les entreprises / employés à
 * ceux ayant interagi avec un client de cette origine ; changer la source
 * re-interroge le serveur (clé de cache = source).
 */
export function useInteractionFilters(source) {
  const scopedSource = source || ''

  const { data } = useQuery({
    queryKey: ['prospect-interaction-filters', scopedSource],
    queryFn: () => api.get('/clients/interaction-filters', { params: scopedSource ? { source: scopedSource } : {} }),
    staleTime: 1000 * 60 * 5, // 5 min
    retry: false,
  })

  const options = data?.data ?? {}

  return {
    enterprises: Array.isArray(options.enterprises) ? options.enterprises : [],
    employees: Array.isArray(options.employees) ? options.employees : [],
  }
}
