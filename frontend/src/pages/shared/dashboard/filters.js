// Helpers du filtre de période global du dashboard (Année → Mois → Semaine),
// partagés par la barre de filtres et le hook (libellés + bornes de semaine).

export const MONTH_LABELS = [
  'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
  'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre',
]

// Jours du mois (`new Date(year, month, 0)` → dernier jour).
export const daysInMonth = (year, month) => new Date(year, month, 0).getDate()

// Le serveur découpe le mois en semaines de 7 jours à partir du jour 1
// (1–7, 8–14, …, la dernière est partielle) → ceil(days / 7) semaines.
export const countWeeks = (year, month) => Math.ceil(daysInMonth(year, month) / 7)

// Bornes affichées par l'option « Semaine N » : [premier jour, dernier jour].
export const weekRange = (year, month, week) => {
  const from = (week - 1) * 7 + 1
  return [from, Math.min(week * 7, daysInMonth(year, month))]
}
