import { api } from './client.js'

export function storeOutcomeApi(clientId, payload) {
  return api.post(`/clients/${clientId}/outcome`, payload)
}

// type : 'CALL_BACK' (page « Rappels », défaut) ou 'BV' (page « Auto-rappels »).
export function listRemindersApi(type = 'CALL_BACK') {
  return api.get('/reminders', { params: { type } })
}

export function remindersCountApi(type = 'CALL_BACK') {
  return api.get('/reminders/count', { params: { type } })
}
