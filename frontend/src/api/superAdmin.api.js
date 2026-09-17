import { api } from './client.js'

// Admin management (SUPER_ADMIN only)
export function listAdminsApi(params = {}) {
  return api.get('/users', { params: { ...params, role: 'ADMIN' } })
}

export function createAdminApi(payload) {
  return api.post('/admins', payload)
}

export function updateAdminApi(id, payload) {
  return api.put(`/users/${id}`, payload)
}

export function deleteAdminApi(id) {
  return api.delete(`/users/${id}`)
}

export function toggleAdminStatusApi(id, status) {
  return api.patch(`/users/${id}/status`, { status })
}