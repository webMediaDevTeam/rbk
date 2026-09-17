import { api } from './client.js'

export function storeOutcomeApi(clientId, payload) {
  return api.post(`/clients/${clientId}/outcome`, payload)
}

export function releaseClientApi(clientId) {
  return api.post(`/clients/${clientId}/release`)
}

export function listRemindersApi() {
  return api.get('/reminders')
}

export function remindersCountApi() {
  return api.get('/reminders/count')
}
