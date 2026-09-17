import { api } from './client.js'

export function loginApi(payload) {
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
