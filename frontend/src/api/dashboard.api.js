import { api } from './client.js'

// Role-aware dashboard statistics (shared by all roles)
export function getDashboardStatsApi() {
  return api.get('/dashboard/stats')
}
