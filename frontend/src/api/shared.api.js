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

// Disponibilité d'un nom d'utilisateur — contrôle en temps réel (debounce)
// des modales employé : `users.username` est unique en base. `exceptId` =
// l'employé en cours d'édition, exclu de la comparaison.
// Réponse : `{success, data: {username, valid, available}}`.
export function checkUsernameAvailabilityApi(username, exceptId) {
  return api.get('/users/username-available', {
    params: exceptId ? { username, id: exceptId } : { username },
  })
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

// Sources — répertoire **fermé en lecture seule** (aucun CRUD côté API :
// `GET /sources` est la seule route), lignes initialisées par `SourceSeeder`.
// Alimente le sélecteur « Source » des modales « Créer / Modifier une
// entreprise ». Réponse : `{success, data: [{id, name}]}`.
export function listSourcesApi() {
  return api.get('/sources')
}

// RingCentral — appareils de l'account + numéros assignés (consultation
// seule, ADMIN + SUPER_ADMIN) : sert au select « Appareil / numéro source »
// des modales employé.
// Réponse : `{success, data: [{id, name, phoneLines, phoneNumbers, …}]}`.
//
// `enterpriseId` (modales employé) : appareils **du compte RingCentral de
// cette entreprise** (`enterprises.ringcentral_*`, repli `.env` sans champ
// renseigné). Omis = compte `.env`.
export function listRingCentralDevicesApi(enterpriseId = null) {
  return api.get('/call-logs/devices', {
    params: enterpriseId ? { per_page: 250, enterprise_id: enterpriseId } : { per_page: 250 },
  })
}

export function listRingCentralUsersApi() {
  return api.get('/call-logs/users', { params: { per_page: 250 } })
}

// RingCentral — synchronisation (ADMIN + SUPER_ADMIN) :
//   * `syncRingCentralEmployeesApi()` : correspondance employé ↔ poste +
//     numéros, écrite dans `employees.ringcentral_*` (Phase 1) ;
//   * `syncEmployeeCallLogsApi(id)`   : journal d'un employé récupéré chez
//     RingCentral puis rangé dans `call_logs` (Phase 2).
// Réponse : `{success, data: {…rapport}}`.
export function syncRingCentralEmployeesApi() {
  return api.post('/call-logs/sync/employees')
}

export function syncEmployeeCallLogsApi(id) {
  return api.post(`/call-logs/employees/${id}/logs/sync`)
}

// Audio d'un enregistrement (ADMIN + SUPER_ADMIN) : le `contentUri` exige
// l'en-tête `Authorization`, qu'un `<audio>` ne sait pas envoyer → le proxy
// backend renvoie le flux, lu par `components/RecordingPlayer.jsx`.
// Réponse : un `Blob` (l'intercepteur renvoie `response.data`).
export function getCallRecordingContentApi(recordingId) {
  return api.get(`/call-logs/recordings/${encodeURIComponent(recordingId)}/content`, { responseType: 'blob' })
}
