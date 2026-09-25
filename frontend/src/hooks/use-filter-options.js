import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client.js'

/**
 * Options distinctes des filtres de prospects : municipalité, catégorie et
 * région administrative (valeurs sans doublons lues dans la table clients).
 *
 * Le serveur met tout ça en cache une semaine (Client::distinctValues(),
 * invalidé à chaque changement de clients) : inutile de re-appeler l'API à
 * chaque rendu, on garde le résultat une heure côté client.
 */
export function useFilterOptions() {
  const { data } = useQuery({
    queryKey: ['prospect-filter-options'],
    queryFn: () => api.get('/filters'),
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
