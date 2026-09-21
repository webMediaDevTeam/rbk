import { api } from './client.js'

// Commercial management (ADMIN, SUPER_ADMIN)
export function listCommerciauxApi(params = {}) {
  return api.get('/users', { params: { ...params, role: 'COMERCIAL' } })
}

export function createCommercialApi(payload) {
  return api.post('/users', { ...payload, role: 'COMERCIAL' })
}

export function updateCommercialApi(id, payload) {
  return api.put(`/users/${id}`, payload)
}

export function deleteCommercialApi(id) {
  return api.delete(`/users/${id}`)
}

export function toggleCommercialStatusApi(id, status) {
  return api.patch(`/users/${id}/status`, { status })
}

export function statistiquesCommerciauxApi() {
  return api.get('/commerciaux/statistiques')
}
