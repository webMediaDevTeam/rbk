import { devLog, devTable } from '@/lib/dev-log.js'
import { GEO_OUTCOME } from '@/lib/geolocation.js'

/**
 * Trace **de développement** du contrôle GPS à la connexion.
 *
 * Tout passe par `devLog` / `devTable` : rien n'est écrit dans la console en
 * production (`import.meta.env.DEV` faux, code éliminé au build).
 *
 * D'où viennent les zones ? Le navigateur connecté n'a pas accès à
 * `GET /gps/zones` (route ADMIN) : les zones « enregistrées » et les distances
 * arrivent donc avec la **réponse du serveur** (`geofence_debug`, servi
 * uniquement quand l'API tourne en mode debug). C'est aussi ce qui garantit que
 * les chiffres affichés sont ceux qui ont **réellement décidé** de la connexion
 * — même Haversine, même arrondis, côté serveur.
 */

const LABEL = 'GPS · connexion'

/** `45.501700` — `—` si la valeur manque. */
const coord = (value) => (typeof value === 'number' ? value.toFixed(6) : '—')

/** `123.4 m` — `—` si la valeur manque. */
const meters = (value) => (typeof value === 'number' ? `${value.toFixed(1)} m` : '—')

/**
 * Étapes 1 et 2 — permission, puis position reçue. Appelées **dans le
 * handler** du bouton, dès que le navigateur a répondu (jamais au montage de
 * l'écran).
 *
 * @returns {boolean} `false` si la suite du trace n'a pas de sens (contrôle
 *   éteint, permission refusée, timeout) — l'appelant s'arrête là.
 */
export function logPermissionAndPosition({ geofenceEnabled, outcome, position }) {
  if (!geofenceEnabled) {
    devLog(`[${LABEL}] contrôle GPS désactivé côté serveur — le navigateur n'est pas interrogé.`)

    return false
  }

  devLog(`[${LABEL}] permission géolocalisation : ${outcome === GEO_OUTCOME.granted ? 'accordée' : `refusée/absente (${outcome})`}`)

  if (outcome !== GEO_OUTCOME.granted || !position) {
    devLog(`[${LABEL}] pas de position (${outcome}) — arrêt du trace, la requête part sans coordonnées.`)

    return false
  }

  devLog(
    `[${LABEL}] ma position : lat ${coord(position.lat)}, lng ${coord(position.lng)}, `
    + `précision ${meters(position.accuracy)}`,
  )

  return true
}

/**
 * Étapes 3 à 6 — zones enregistrées (`console.table`), zone courante,
 * comparaison avec chaque zone (distance Haversine, rayon, dedans/dehors,
 * différence), puis résultat final.
 *
 * @param {object|null} debug bloc `geofence_debug` renvoyé par l'API
 */
export function logZoneComparison(debug) {
  if (!debug) {
    devLog(`[${LABEL}] trace serveur absent — le bloc « geofence_debug » n'est servi qu'en mode debug de l'API (APP_DEBUG).`)

    return
  }

  devLog(
    `[${LABEL}] contexte serveur : contrôle ${debug.enabled ? 'activé' : 'désactivé'}, `
    + `rôle ${debug.role} ${debug.enforced ? 'contraint' : 'non contraint'}, `
    + `dérogation ${debug.bypass ? 'oui' : 'non'}, précision max ${meters(debug.max_accuracy_m)}`,
  )

  const zones = debug.zones ?? []

  devTable(
    zones.map((zone) => ({
      id: zone.id,
      nom: zone.name,
      'centre lat': zone.latitude,
      'centre lng': zone.longitude,
      'rayon (m)': zone.radius_m,
    })),
    `[${LABEL}] zones enregistrées : aucune zone active`,
  )

  const matched = debug.matched_zone

  if (matched) {
    devLog(
      `[${LABEL}] ma zone courante : « ${matched.name} » — centre ${coord(matched.latitude)}, `
      + `${coord(matched.longitude)}, rayon ${meters(matched.radius_m)}, `
      + `je suis à ${meters(matched.distance_m)} du centre`,
    )
  } else {
    devLog(`[${LABEL}] ma zone courante : aucune — je ne suis dans aucune zone active`)
  }

  devTable(
    zones.map((zone) => ({
      'zone': zone.name,
      'distance (m)': typeof zone.distance_m === 'number' ? Number(zone.distance_m.toFixed(1)) : null,
      'rayon (m)': zone.radius_m,
      'différence (rayon − distance)': typeof zone.difference_m === 'number' ? Number(zone.difference_m.toFixed(1)) : null,
      'résultat': zone.inside === null ? 'sans position' : zone.inside ? 'DEDANS' : 'DEHORS',
    })),
    `[${LABEL}] comparaison : aucune zone à comparer`,
  )

  devLog(
    `[${LABEL}] résultat : ${matched ? `zone « ${matched.name} » correspondante` : 'No matching zone — aucune zone ne couvre cette position'}`,
  )
}

/**
 * Conclusion de la comparaison : rend les zones à partir de ce que la requête de
 * connexion a renvoyé — réponse 200 (le jeton est émis, on montre quand même la
 * zone qui a convaincu le serveur) ou 403 de géorepérage.
 *
 * Un refus d'identifiants (401) n'a **aucun** trace : le contrôle GPS n'est
 * évalué qu'après vérification du mot de passe, et un inconnu ne doit pas
 * recevoir la liste des zones — c'est voulu, pas une régression.
 *
 * @param {{ active: boolean, data?: object|null, error?: object|null }} args
 *   `active` = la permission a été accordée et une position a été reçue.
 */
export function logLoginGeofenceResult({ active, data, error }) {
  if (!active) return

  if (error?.response?.status === 401) {
    devLog(`[${LABEL}] identifiants refusés (401) — le contrôle GPS n'a pas été évalué, rien à comparer.`)

    return
  }

  const payload = data ?? error?.response?.data ?? null

  logZoneComparison(payload?.geofence_debug ?? null)
}
