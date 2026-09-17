import { useMutation } from '@tanstack/react-query'
import { loginApi } from '@/api/auth.api.js'
import { useAuth } from '@/context/AuthContext.jsx'

export function useConnexion({ onSuccess } = {}) {
  const { login } = useAuth()

  return useMutation({
    mutationFn: loginApi,
    onSuccess: (data, variables) => {
      login({ utilisateur: data.utilisateur, jeton: data.jeton, remember: Boolean(variables?.remember) })
      onSuccess?.(data)
    },
  })
}
