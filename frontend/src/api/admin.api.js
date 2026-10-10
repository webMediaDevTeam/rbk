import { api } from './client.js'

// Entreprise management (ADMIN, SUPER_ADMIN)
export function listEntreprisesApi(params = {}) {
  return api.get('/enterprises', { params })
}

// Statistiques d'une entreprise — page `/entreprises/:id`
// (`analytics` + tableau des employés + historique commun paginé).
export function getEntrepriseStatsApi(id, params = {}) {
  return api.get(`/entreprises/${id}/stats`, { params })
}

export function checkEntrepriseRingCentralApi(id) {
  return api.get(`/entreprises/${id}/ringcentral-status`)
}

export function getEntrepriseRingCentralCredentialsApi(id) {
  return api.get(`/entreprises/${id}/ringcentral-credentials`, {
    headers: { 'Cache-Control': 'no-cache' },
  })
}

// Onglet « RingCentral » de la fiche entreprise : échange JWT →
// access_token (métadonnées masquées côté serveur).
export function getEntrepriseRingCentralTokenApi(id) {
  return api.get(`/entreprises/${id}/ringcentral-token`, {
    headers: { 'Cache-Control': 'no-cache' },
  })
}

// Correction des identifiants RingCentral en base (clé absente = conservée,
// '' = retirée).
export function updateEntrepriseRingCentralCredentialsApi(id, payload) {
  return api.put(`/entreprises/${id}/ringcentral-credentials`, payload)
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
