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
    // L'historique doit être **toujours frais** à l'entrée sur l'onglet :
    // une « Suite appel » ou un appel RingCentral peuvent avoir écrit des
    // notes/notes d'issue pendant que la page était cachée (le `staleTime`
    // global de 30 s servirait le cache sans recharger).
    staleTime: 0,
    refetchOnMount: 'always',
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
