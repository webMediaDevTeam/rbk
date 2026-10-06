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

export function updateReservationGroupApi(id, payload) {
  return api.patch(`/reservation-groups/${id}`, payload)
}

// Compteur header/sidebar : réservations actives du commercial connecté
export function activeReservationsCountApi() {
  return api.get('/reservations/active-count')
}

// **Libérer la liste** (§7.1 de docs/RULES.md) : tous les prospects encore
// « en attente » du connecté redeviennent `AVAILABLE` — débloque un nouveau
// lot quand le serveur refuse (`409 unfinished_treatment`).
export function releasePendingReservationApi() {
  return api.post('/reservations/release-pending')
}

// Admin/Super Admin client view (read-only + blacklist management)
export function listAdminClientsApi(params = {}) {
  return api.get('/commercials/clients', { params })
}

export function getAdminClientApi(id) {
  return api.get(`/commercials/clients/${id}`)
}

export function adminBlacklistClientApi(id, note) {
  return api.post(`/commercials/clients/${id}/blacklist`, { note })
}

// Unic endpoint d'unblock (réservé Admin / Super Admin)
export function adminUnblockClientApi(id) {
  return api.post(`/liste-noire/${id}/debloquer`)
}

// Saisie / correction du **numéro de téléphone** depuis l'accès Admin
// (`PATCH commercials/clients/{id}/phone`) — un client « Sans téléphone »
// retrouve alors son seau « Disponible » / « Blacklist ».
export function adminUpdateClientPhoneApi(id, phone) {
  return api.patch(`/commercials/clients/${id}/phone`, { phone })
}

// Appel sortant de l'employé (bouton « Appeler » : Mes listes, Rappels, BV).
// La source (`from`) est résolue côté API dans `employees.ringcentral_from_number`,
// le navigateur n'envoie que la destination. `record: true` → l'appel est
// enregistré (démarrage immédiat, retenté par `startCallRecordingApi` tant
// que la partie n'est pas connectée).
export function callMyNumberApi(payload = {}) {
  return api.post('/call-logs/my-call', payload)
}

// (Re)démarre l'enregistrement d'une partie de session — retenté par le
// hook `useDirectCall` tant que la partie n'est pas encore connectée.
export function startCallRecordingApi(sessionId, partyId) {
  return api.post(
    `/call-logs/calls/${encodeURIComponent(sessionId)}/parties/${encodeURIComponent(partyId)}/record`
  )
}