import { api } from './client.js'

export function listClientNotesApi(clientId) {
  return api.get(`/clients/${clientId}/notes`)
}

export function createNoteApi(payload) {
  return api.post('/notes', payload)
}

export function deleteNoteApi(id) {
  return api.delete(`/notes/${id}`)
}
