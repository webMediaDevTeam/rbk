import { useQuery } from '@tanstack/react-query'
import {
  listCommercialProspectsApi,
  getCommercialProspectApi,
} from '@/api/commercial.api.js'

export function useCommercialProspectList(params = {}) {
  return useQuery({
    queryKey: ['commercial-prospects', params],
    queryFn: () => listCommercialProspectsApi(params),
  })
}

export function useCommercialProspect(id) {
  return useQuery({
    queryKey: ['commercial-prospect', id],
    queryFn: () => getCommercialProspectApi(id),
    enabled: !!id,
  })
}