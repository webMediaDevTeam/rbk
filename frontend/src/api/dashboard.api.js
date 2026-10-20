import { api } from './client.js'

// Statistiques du dashboard, filtrées par la période **globale** du tableau
// de bord : `year` (défaut serveur : année courante), `month` (1-12, absent
// = tous les mois de l'année) et `week` (semaine du mois : 1 = jours 1–7,
// 2 = jours 8–14… ; ignorée sans `month`). Cartes, graphique, réservations
// et « Meilleurs employés » partagent ces bornes ; seul
// `stats.blacklisted_clients` reste un instantané (clients actuellement
// blacklistés).
export function getDashboardStatsApi({ year, month, week } = {}) {
  const params = {}
  if (year) params.year = year
  if (month) params.month = month
  if (week) params.week = week
  return api.get('/dashboard/stats', { params })
}
