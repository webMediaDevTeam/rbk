import { api } from './client.js'

// Role-aware dashboard statistics (shared by all roles). `period` pilote la
// série du graphique « Évolution journalière des appels » (`week` | `month`
// | `year`, défaut `week` côté serveur) ; `year` / `month` sélectionnent un
// mois calendaire précis (`period=month` → évolution par jour du mois) ou une
// année précise (`period=year` → évolution par mois de l'année). Les cartes
// d'appels comptent toujours la journée en cours.
export function getDashboardStatsApi({ period, year, month } = {}) {
  const params = {}
  if (period) params.period = period
  if (year) params.year = year
  if (month) params.month = month
  return api.get('/dashboard/stats', { params })
}
