import axios from 'axios'
import { api } from './client.js'

const API_ROOT = import.meta.env.VITE_API_URL ?? 'http://localhost:8000'

export async function loginApi(payload) {
  await axios.get(`${API_ROOT}/sanctum/csrf-cookie`, {
    withCredentials: true,
    headers: {
      Accept: 'application/json',
    },
  })

  return api.post('/auth/login', payload)
}

export function sendLoginOtpApi(payload) {
  return api.post('/auth/login/otp', payload)
}

export function verifyLoginOtpApi(payload) {
  return api.post('/auth/login/otp/verify', payload)
}

export function forgotPasswordApi(payload) {
  return api.post('/auth/forgot-password', payload)
}

export function verifyForgotPasswordOtpApi(payload) {
  return api.post('/auth/forgot-password/verify', payload)
}

export function resetForgotPasswordApi(payload) {
  return api.post('/auth/forgot-password/reset', payload)
}

export function verifyAccountApi(payload) {
  return api.post('/auth/verify-account', payload)
}

export function resendVerificationApi(payload) {
  return api.post('/auth/resend-verification', payload)
}
