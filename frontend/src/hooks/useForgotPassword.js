import { useMutation } from '@tanstack/react-query'
import { forgotPasswordApi } from '../api/auth.api.js'

export function useForgotPassword({ onSuccess } = {}) {
  return useMutation({
    mutationFn: forgotPasswordApi,
    onSuccess: (data) => onSuccess?.(data),
  })
}