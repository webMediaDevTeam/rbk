import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  storeOutcomeApi,
  releaseClientApi,
  listRemindersApi,
  remindersCountApi,
} from '@/api/outcomes.api.js'

export function useStoreOutcome() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ clientId, ...payload }) => storeOutcomeApi(clientId, payload),
    onSuccess: (_data, variables) => {
      qc.invalidateQueries({ queryKey: ['commercial-prospect', variables.clientId] })
      qc.invalidateQueries({ queryKey: ['client-notes', variables.clientId] })
      qc.invalidateQueries({ queryKey: ['commercial-prospects'] })
      qc.invalidateQueries({ queryKey: ['reservation-groups'] })
      qc.invalidateQueries({ queryKey: ['reminders'] })
      qc.invalidateQueries({ queryKey: ['reminders-count'] })
    },
  })
}

export function useReleaseClient() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (clientId) => releaseClientApi(clientId),
    onSuccess: (_data, clientId) => {
      qc.invalidateQueries({ queryKey: ['commercial-prospect', clientId] })
      qc.invalidateQueries({ queryKey: ['commercial-prospects'] })
      qc.invalidateQueries({ queryKey: ['reservation-groups'] })
    },
  })
}

export function useReminders() {
  return useQuery({
    queryKey: ['reminders'],
    queryFn: listRemindersApi,
  })
}

export function useRemindersCount(enabled = true) {
  return useQuery({
    queryKey: ['reminders-count'],
    queryFn: remindersCountApi,
    refetchInterval: 60000,
    enabled,
  })
}