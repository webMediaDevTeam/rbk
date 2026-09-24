import { api } from './client.js'

export function storeOutcomeApi(clientId, payload) {
  return api.post(`/clients/${clientId}/outcome`, payload)
}

// type : 'INJOINABLE' (page « Rappels », défaut) ou 'BV' (page « Auto-rappels »).
export function listRemindersApi(type = 'INJOINABLE') {
  return api.get('/reminders', { params: { type } })
}

export function remindersCountApi(type = 'INJOINABLE') {
  return api.get('/reminders/count', { params: { type } })
}
