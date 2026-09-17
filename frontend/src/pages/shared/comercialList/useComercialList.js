import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  listCommerciauxApi,
  createCommercialApi,
  updateCommercialApi,
  deleteCommercialApi,
  toggleCommercialStatusApi,
  statistiquesCommerciauxApi,
} from '@/api/entreprise.api.js'

export function useComercialList(params = {}) {
  return useQuery({
    queryKey: ['commerciaux', params],
    queryFn: () => listCommerciauxApi(params),
  })
}

export function useComercialStats() {
  return useQuery({
    queryKey: ['commerciaux-stats'],
    queryFn: statistiquesCommerciauxApi,
  })
}

export function useCreateCommercial() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: createCommercialApi,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['commerciaux'] })
      qc.invalidateQueries({ queryKey: ['commerciaux-stats'] })
    },
  })
}

export function useUpdateCommercial() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, ...payload }) => updateCommercialApi(id, payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['commerciaux'] }),
  })
}

export function useDeleteCommercial() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: deleteCommercialApi,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['commerciaux'] })
      qc.invalidateQueries({ queryKey: ['commerciaux-stats'] })
    },
  })
}

export function useToggleCommercialStatus() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, status }) => toggleCommercialStatusApi(id, status),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['commerciaux'] }),
  })
}