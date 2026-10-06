import {
  useQuery,
  useMutation,
  useQueryClient,
} from '@tanstack/react-query'
import {
  storeOutcomeApi,
  listRemindersApi,
  remindersCountApi,
} from '@/api/outcomes.api.js'
import { activeReservationsCountApi } from '@/api/commercial.api.js'

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
      qc.invalidateQueries({ queryKey: ['active-reservations-count'] })
      // Issue d'appel = client qui sort du seau « Disponible » (ou y
      // revient) : badges de la colonne « Statut », liste admin et détail
      // de la liste doivent être recalculés.
      qc.invalidateQueries({ queryKey: ['prospect-kpis'] })
      qc.invalidateQueries({ queryKey: ['admin-clients-history'] })
      qc.invalidateQueries({ queryKey: ['reservation-group'] })
      qc.invalidateQueries({ queryKey: ['dashboard-stats'] })
    },
  })
}

// type : 'CALL_BACK' (page « Rappels ») ou 'BV' (page « Auto-rappels »).
export function useReminders(type = 'CALL_BACK') {
  return useQuery({
    queryKey: ['reminders', type],
    queryFn: () => listRemindersApi(type),
  })
}

// Compteur des rappels échus (badge de la sidebar), rafraîchi chaque minute.
export function useRemindersCount(enabled = true, type = 'CALL_BACK') {
  return useQuery({
    queryKey: ['reminders-count', type],
    queryFn: () => remindersCountApi(type),
    refetchInterval: 60000,
    enabled,
  })
}

export function useActiveReservationsCount(enabled = true) {
  return useQuery({
    queryKey: ['active-reservations-count'],
    queryFn: activeReservationsCountApi,
    refetchInterval: 60000,
    enabled,
  })
}