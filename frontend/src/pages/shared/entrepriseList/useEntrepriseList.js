import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  listEntreprisesApi,
  createEntrepriseApi,
  updateEntrepriseApi,
  deleteEntrepriseApi,
  toggleEntrepriseStatusApi,
} from '../../../api/admin.api.js'

export function useEntrepriseList(params = {}) {
  return useQuery({
    queryKey: ['entreprises', params],
    queryFn: () => listEntreprisesApi(params),
  })
}

export function useCreateEntreprise() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: createEntrepriseApi,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['entreprises'] }),
  })
}

export function useUpdateEntreprise() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, ...payload }) => updateEntrepriseApi(id, payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['entreprises'] }),
  })
}

export function useDeleteEntreprise() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: deleteEntrepriseApi,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['entreprises'] }),
  })
}

export function useToggleEntrepriseStatus() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, status }) => toggleEntrepriseStatusApi(id, status),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['entreprises'] }),
  })
}