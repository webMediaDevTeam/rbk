import { api } from './client.js'

// Statistiques du dashboard, filtrées par la période **globale** du tableau
// de bord : `year` (défaut serveur : année courante), `month` (1-12, absent
// = tous les mois de l'année), `week` (semaine du mois : 1 = jours 1–7,
// 2 = jours 8–14… ; ignorée sans `month`) et `day` (jour seul du mois choisi,
// 1-31 ; ignoré sans `month` et il rend `week` sans effet). Cartes,
// graphique, réservations et « Meilleurs employés » partagent ces bornes ;
// seul `stats.blacklisted_clients` reste un instantané (clients actuellement
// blacklistés).
//
// `source` (gestionnaires seulement) : l'origine active de la Grande liste —
// la **même** sélection, persistée dans `localStorage`, borne tous les
// chiffres liés aux clients. `''` = « Toutes » (paramètre omis). Le serveur
// l'ignore pour les autres rôles.
//
// `range` (fenêtre **roulante**) : `last3days` / `last6months` — une fenêtre
// glissante ancrée sur aujourd'hui, qui **prime** sur la cascade calendaire.
// Posée, les réglages `year`/`month`/`week`/`day` sont omis (le serveur les
// ignore de toute façon tant que `range` est présent).
export function getDashboardStatsApi({ year, month, week, day, range, source } = {}) {
  const params = {}
  if (range) {
    params.range = range
  } else {
    if (year) params.year = year
    if (month) params.month = month
    if (week) params.week = week
    if (day) params.day = day
  }
  if (source) params.source = source
  return api.get('/dashboard/stats', { params })
}
