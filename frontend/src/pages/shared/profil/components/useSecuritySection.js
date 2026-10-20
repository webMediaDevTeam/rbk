import { useState } from 'react'
import { toast } from 'sonner'
import { useUpdateProfilePassword } from '@/pages/shared/profil/useProfil.js'
import { getApiErrorMessage } from '@/lib/api-errors.js'

const MIN_PASSWORD_LENGTH = 8

// Formulaire simple de changement de mot de passe (rôles non-commerciaux) :
// mot de passe actuel + nouveau + confirmation. Plus d'OTP, plus d'étapes.
export function useSecuritySection() {
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [error, setError] = useState(null)

  const updatePasswordMut = useUpdateProfilePassword()
  const isBusy = updatePasswordMut.isPending
  const updatePasswordPending = updatePasswordMut.isPending

  const updatePassword = (e) => {
    e.preventDefault()
    setError(null)

    if (!currentPassword) {
      setError('Saisissez votre mot de passe actuel.')
      return
    }
    if (password.length < MIN_PASSWORD_LENGTH) {
      setError(`Le mot de passe doit contenir au moins ${MIN_PASSWORD_LENGTH} caractères.`)
      return
    }
    if (password !== confirmation) {
      setError('La confirmation ne correspond pas au mot de passe.')
      return
    }

    updatePasswordMut.mutate(
      {
        current_password: currentPassword,
        password,
        password_confirmation: confirmation,
      },
      {
        onSuccess: (response) => {
          toast.success(response?.message ?? response?.data?.message ?? 'Mot de passe mis à jour.')
          setCurrentPassword('')
          setPassword('')
          setConfirmation('')
        },
        onError: (err) => setError(getApiErrorMessage(err)),
      }
    )
  }

  return {
    currentPassword,
    onCurrentPasswordChange: (e) => setCurrentPassword(e.target.value),
    password,
    onPasswordChange: (e) => setPassword(e.target.value),
    confirmation,
    onConfirmationChange: (e) => setConfirmation(e.target.value),
    error,
    isBusy,
    updatePasswordPending,
    updatePassword,
  }
}
