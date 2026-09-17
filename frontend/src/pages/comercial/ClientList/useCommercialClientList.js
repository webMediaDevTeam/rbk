import { useQuery } from '@tanstack/react-query'
import {
  listCommercialClientsApi,
  getCommercialClientApi,
} from '../../../api/commercial.api.js'

export function useCommercialClientList(params = {}) {
  return useQuery({
    queryKey: ['commercial-clients', params],
    queryFn: () => listCommercialClientsApi(params),
  })
}

export function useCommercialClient(id) {
  return useQuery({
    queryKey: ['commercial-client', id],
    queryFn: () => getCommercialClientApi(id),
    enabled: !!id,
  })
}