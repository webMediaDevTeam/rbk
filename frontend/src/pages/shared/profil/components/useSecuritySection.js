import { useState } from 'react'
import { toast } from 'sonner'
import {
  useSendProfilePasswordOtp,
  useUpdateProfilePassword,
  useVerifyProfilePasswordOtp,
} from '@/pages/shared/profil/useProfil.js'
import { getApiErrorMessage } from '@/lib/api-errors.js'

const MIN_PASSWORD_LENGTH = 8

export function useSecuritySection({ user }) {
  const [step, setStep] = useState('idle')
  const [otp, setOtp] = useState('')
  const [passwordUpdateToken, setPasswordUpdateToken] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [error, setError] = useState(null)
  const [canResendOtp, setCanResendOtp] = useState(false)

  const sendOtpMut = useSendProfilePasswordOtp()
  const verifyOtpMut = useVerifyProfilePasswordOtp()
  const updatePasswordMut = useUpdateProfilePassword()

  const email = user?.email ?? 'votre adresse email'
  const isBusy = sendOtpMut.isPending || verifyOtpMut.isPending || updatePasswordMut.isPending
  const sendOtpPending = sendOtpMut.isPending
  const verifyOtpPending = verifyOtpMut.isPending
  const updatePasswordPending = updatePasswordMut.isPending
  const otpSubmitDisabled = isBusy || otp.length !== 6

  const requestOtp = () => {
    setError(null)
    setCanResendOtp(false)
    setOtp('')

    sendOtpMut.mutate(undefined, {
      onSuccess: (response) => {
        toast.success(response?.message ?? response?.data?.message ?? 'Code envoyé.')
        setStep('otp')
      },
      onError: (err) => setError(getApiErrorMessage(err)),
    })
  }

  const verifyOtp = (e) => {
    e.preventDefault()
    setError(null)
    setCanResendOtp(false)

    verifyOtpMut.mutate(
      { code: otp },
      {
        onSuccess: (response) => {
          setPasswordUpdateToken(response?.password_update_token ?? response?.data?.password_update_token ?? '')
          setStep('password')
          toast.success(response?.message ?? response?.data?.message ?? 'Code vérifié.')
        },
        onError: (err) => {
          setError(getApiErrorMessage(err))
          setCanResendOtp(err.response?.data?.code === 'PASSWORD_OTP_EXPIRED')
        },
      }
    )
  }

  const updatePassword = (e) => {
    e.preventDefault()
    setError(null)

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
        password_update_token: passwordUpdateToken,
        password,
        password_confirmation: confirmation,
      },
      {
        onSuccess: (response) => {
          toast.success(response?.message ?? response?.data?.message ?? 'Mot de passe mis à jour.')
          setStep('idle')
          setOtp('')
          setPassword('')
          setConfirmation('')
          setPasswordUpdateToken('')
          setCanResendOtp(false)
        },
        onError: (err) => {
          setError(getApiErrorMessage(err))
          if (err.response?.data?.code === 'PASSWORD_UPDATE_TOKEN_EXPIRED') {
            setStep('otp')
            setPasswordUpdateToken('')
            setCanResendOtp(true)
          }
        },
      }
    )
  }

  return {
    step,
    otp,
    onOtpChange: setOtp,
    password,
    onPasswordChange: (e) => setPassword(e.target.value),
    confirmation,
    onConfirmationChange: (e) => setConfirmation(e.target.value),
    error,
    canResendOtp,
    email,
    isBusy,
    sendOtpPending,
    verifyOtpPending,
    updatePasswordPending,
    otpSubmitDisabled,
    requestOtp,
    verifyOtp,
    updatePassword,
  }
}