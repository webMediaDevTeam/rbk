import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  listClientNotesApi,
  createNoteApi,
  deleteNoteApi,
} from '@/api/notes.api.js'

export function useClientNotes(clientId) {
  return useQuery({
    queryKey: ['client-notes', clientId],
    queryFn: () => listClientNotesApi(clientId),
    enabled: !!clientId,
  })
}

export function useCreateNote() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: createNoteApi,
    onSuccess: (_data, variables) => {
      qc.invalidateQueries({ queryKey: ['client-notes', variables.client_id] })
    },
  })
}

export function useDeleteNote() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: deleteNoteApi,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['client-notes'] })
    },
  })
}
