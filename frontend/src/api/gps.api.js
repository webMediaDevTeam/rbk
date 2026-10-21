import { api } from './client.js'

// Zones de géorepérage GPS (ADMIN, SUPER_ADMIN) — page « GPS ».
// Le contrôle de connexion lui-même est serveur (`GeofenceService`) ;
// ces routes ne font que gérer la liste des cercles autorisés.
export function listGpsZonesApi() {
  return api.get('/gps/zones')
}

export function createGpsZoneApi(payload) {
  return api.post('/gps/zones', payload)
}

export function updateGpsZoneApi(id, payload) {
  return api.put(`/gps/zones/${id}`, payload)
}

export function deleteGpsZoneApi(id) {
  return api.delete(`/gps/zones/${id}`)
}

// Réglages **globaux** du géorepérage (haut de la page « GPS ») : le
// coupe-circuit du contrôle à la connexion est stocké en base, il se bascule
// donc sans redéploiement ni `config:clear`.
export function getGpsSettingsApi() {
  return api.get('/gps/settings')
}

export function updateGpsSettingsApi(geofenceEnabled) {
  return api.put('/gps/settings', { geofence_enabled: Boolean(geofenceEnabled) })
}

// Dérogation « Connexion hors zones » d'un compte contraint
// (COMERCIAL / OPERATOR) — colonne de la page Employés.
export function toggleGpsBypassApi(id, gpsBypass) {
  return api.patch(`/users/${id}/gps-bypass`, { gps_bypass: gpsBypass })
}
