import { api } from './client.js'

// Commercial prospect management (COMERCIAL)
export function listCommercialProspectsApi(params = {}) {
  return api.get('/clients', { params })
}

export function getCommercialProspectApi(id) {
  return api.get(`/clients/${id}`)
}

export function blacklistClientApi(id, note) {
  return api.post(`/clients/${id}/blacklist`, { note })
}

export function reserveCommercialProspectsApi(payload = {}) {
  return api.post('/clients/reserver', payload)
}

// Reservation groups
export function listReservationGroupsApi(params = {}) {
  return api.get('/reservation-groups', { params })
}

export function getReservationGroupApi(id) {
  return api.get(`/reservation-groups/${id}`)
}

export function releaseGroupPendingApi(id) {
  return api.post(`/reservation-groups/${id}/release-pending`)
}

export function getPendingReservationsCountApi() {
  return api.get('/reservations/pending-count')
}

export function releasePendingReservationsApi() {
  return api.post('/reservations/release-pending')
}