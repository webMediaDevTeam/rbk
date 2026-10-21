/**
 * Journalisation **de développement uniquement** (règle du projet : aucun
 * `console.*` sauvage dans les parcours métier).
 *
 * `import.meta.env.DEV` est la constante Vite équivalente de
 * `process.env.NODE_ENV !== 'production'` : vraie en `vite dev`, remplacée par
 * `false` au build puis éliminée par le bundler. Un navigateur de production
 * n'exécute donc jamais ces lignes — et n'a aucun moyen de les activer.
 *
 * Tout `console.*` du projet passe par ce module : on peut couper, filtrer ou
 * faire tourner le garde-fou depuis un seul endroit.
 */

const isDev = import.meta.env.DEV

/** Vrai quand la journalisation de développement est active (utile pour
 *  sauter un calcul de trace coûtant cher plutôt que son seul affichage). */
export const devLogEnabled = isDev

/** `console.log` en développement, rien sinon. */
export function devLog(...args) {
  if (isDev) console.log(...args)
}

/** `console.warn` en développement, rien sinon. */
export function devWarn(...args) {
  if (isDev) console.warn(...args)
}

/**
 * `console.table` en développement. Un tableau vide ou absent ne s'affiche
 * pas : `emptyMessage` est rendu à la place, plus clair qu'une table vide.
 * (Attention : le second argument de `console.table` filtre des COLONNES, ce
 * n'est pas un libellé — d'où cette signature explicite.)
 */
export function devTable(rows, emptyMessage = '(aucune ligne)') {
  if (!isDev) return

  if (!Array.isArray(rows) || rows.length === 0) {
    console.log(emptyMessage)

    return
  }

  console.table(rows)
}

/**
 * Groupe repliable en développement ; `body()` n'est jamais appelé en
 * production (le calcul de ce qu'on y loggue reste donc bon marché).
 */
export function devGroup(label, body) {
  if (!isDev) return

  console.groupCollapsed(label)

  try {
    body()
  } finally {
    console.groupEnd()
  }
}
