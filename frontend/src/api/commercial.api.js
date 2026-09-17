import { api } from './client.js'

// Commercial client management (COMERCIAL)
export function listCommercialClientsApi(params = {}) {
  return api.get('/clients', { params })
}

export function getCommercialClientApi(id) {
  return api.get(`/clients/${id}`)
}

export function reserveCommercialClientsApi(payload = {}) {
  return api.post('/clients/reserver', payload)
}