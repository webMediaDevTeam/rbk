import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client.js'

/**
 * Options distinctes des filtres de prospects : municipalité, catégorie et
 * région administrative (valeurs sans doublons lues dans la table clients).
 *
 * `source` (onglet « source » actif de la Grande liste) restreint les options
 * aux clients de cette origine : sans ça, le sélecteur propose des libellés
 * qui n'ont aucun client une fois la source appliquée (liste vide inattendue).
 * Changer la source re-interroge donc le serveur (clé de cache = source).
 *
 * Le serveur met tout ça en cache une semaine (Client::distinctValues(),
 * invalidé à chaque changement de clients) : inutile de re-appeler l'API à
 * chaque rendu, on garde le résultat une heure côté client.
 */
export function useFilterOptions(source) {
  const scopedSource = source || ''

  const { data } = useQuery({
    queryKey: ['prospect-filter-options', scopedSource],
    queryFn: () => api.get('/filters', { params: scopedSource ? { source: scopedSource } : {} }),
    staleTime: 1000 * 60 * 60, // 1 h
    retry: false,
  })

  const options = data?.data ?? {}

  return {
    municipalitiesList: options.municipalities ?? [],
    categoriesList: options.categories ?? [],
    regionsList: options.administrative_regions ?? [],
  }
}
