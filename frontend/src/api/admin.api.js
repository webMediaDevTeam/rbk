import { api } from './client.js'

// Entreprise management (ADMIN, SUPER_ADMIN)
export function listEntreprisesApi(params = {}) {
  return api.get('/users', { params: { ...params, role: 'ENTREPRISE' } })
}

export function createEntrepriseApi(payload) {
  return api.post('/entreprises', payload)
}

export function updateEntrepriseApi(id, payload) {
  return api.put(`/users/${id}`, payload)
}

export function deleteEntrepriseApi(id) {
  return api.delete(`/users/${id}`)
}

export function toggleEntrepriseStatusApi(id, status) {
  return api.patch(`/users/${id}/status`, { status })
}

// Blacklist (ADMIN, SUPER_ADMIN)
export function listeNoireApi() {
  return api.get('/liste-noire')
}

export function debloquerClientApi(id) {
  return api.post(`/liste-noire/${id}/debloquer`)
}