import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  sendProfilePasswordOtpApi,
  showUserApi,
  updateProfilePasswordApi,
  updateUserApi,
  verifyProfilePasswordOtpApi,
} from '@/api/shared.api.js'

export function profilQueryKey(userId) {
  return ['profil', userId]
}

export function useProfil(userId) {
  return useQuery({
    queryKey: profilQueryKey(userId),
    queryFn: () => showUserApi(userId),
    enabled: Boolean(userId),
  })
}

export function useUpdateProfile() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, ...payload }) => updateUserApi(id, payload),
    onSuccess: (_data, variables) => {
      qc.invalidateQueries({ queryKey: ['profil', variables.id] })
    },
  })
}

export function useSendProfilePasswordOtp() {
  return useMutation({
    mutationFn: sendProfilePasswordOtpApi,
  })
}

export function useVerifyProfilePasswordOtp() {
  return useMutation({
    mutationFn: verifyProfilePasswordOtpApi,
  })
}

export function useUpdateProfilePassword() {
  return useMutation({
    mutationFn: updateProfilePasswordApi,
  })
}
