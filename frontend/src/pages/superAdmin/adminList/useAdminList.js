import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  listAdminsApi,
  createAdminApi,
  updateAdminApi,
  deleteAdminApi,
  toggleAdminStatusApi,
} from '../../../api/superAdmin.api.js'

export function useAdminList(params = {}) {
  return useQuery({
    queryKey: ['admins', params],
    queryFn: () => listAdminsApi(params),
  })
}

export function useCreateAdmin() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: createAdminApi,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admins'] }),
  })
}

export function useUpdateAdmin() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, ...payload }) => updateAdminApi(id, payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admins'] }),
  })
}

export function useDeleteAdmin() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: deleteAdminApi,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admins'] }),
  })
}

export function useToggleAdminStatus() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, status }) => toggleAdminStatusApi(id, status),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admins'] }),
  })
}