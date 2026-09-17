import { api } from './client.js'

// User Management (shared across roles)
export function listUsersApi(params = {}) {
  return api.get('/users', { params })
}

export function showUserApi(id) {
  return api.get(`/users/${id}`)
}

export function createUserApi(payload) {
  return api.post('/users', payload)
}

export function updateUserApi(id, payload) {
  return api.put(`/users/${id}`, payload)
}

export function deleteUserApi(id) {
  return api.delete(`/users/${id}`)
}

export function toggleUserStatusApi(id, status) {
  return api.patch(`/users/${id}/status`, { status })
}

export function uploadUserAvatarApi(id, formData) {
  return api.post(`/users/${id}/avatar`, formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
}

export function sendProfilePasswordOtpApi() {
  return api.post('/auth/profile/password/otp')
}

export function verifyProfilePasswordOtpApi(payload) {
  return api.post('/auth/profile/password/otp/verify', payload)
}

export function updateProfilePasswordApi(payload) {
  return api.put('/auth/profile/password', payload)
}
