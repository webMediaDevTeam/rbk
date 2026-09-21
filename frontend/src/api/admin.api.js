import { api } from './client.js'

// Entreprise management (ADMIN, SUPER_ADMIN)
export function listEntreprisesApi(params = {}) {
  return api.get('/enterprises', { params })
}

export function createEntrepriseApi(payload) {
  return api.post('/enterprises', payload)
}

export function updateEntrepriseApi(id, payload) {
  return api.put(`/enterprises/${id}`, payload)
}

export function deleteEntrepriseApi(id) {
  return api.delete(`/enterprises/${id}`)
}

export function toggleEntrepriseStatusApi(id, status) {
  return api.patch(`/enterprises/${id}/status`, { status })
}

export function uploadEnterpriseLogoApi(id, formData) {
  return api.post(`/enterprises/${id}/logo`, formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
}

// Blacklist (ADMIN, SUPER_ADMIN)
export function listeNoireApi() {
  return api.get('/liste-noire')
}

export function debloquerClientApi(id) {
  return api.post(`/liste-noire/${id}/debloquer`)
}
