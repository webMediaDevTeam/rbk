import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client.js'

/**
 * Compteurs de `GET clients/overview` — servent à la barre de filtres
 * « Tous + 4 statuts » (Prospects commercial et Prospect list admin).
 *
 * Pas de cache long : le serveur recalcule à chaque appel (les compteurs
 * bougent à chaque réservation / issue d'appel), on rafraîchit côté client
 * toutes les 15 s et au changement de fenêtre.
 */
export function useProspectKpis() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['prospect-kpis'],
    queryFn: () => api.get('/clients/overview'),
    staleTime: 1000 * 15,
    retry: false,
  })

  return { kpis: data?.data ?? null, isLoading, isError }
}
