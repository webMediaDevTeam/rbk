/**
 * Palette « tuiles KPI » : fond pastel, liseré de la même teinte, pastille
 * d'icône **pleine** et grand chiffre coloré. Chaque teinte porte aussi sa
 * variante sombre (les tuiles restent lisibles en thème foncé : le fond
 * pastel devient une coloration 10 % et la pastille s'éclaircit).
 *
 * Les teintes sont **sémantiques et partagées** : « Libre » est vert partout
 * (badges de la liste, tuiles du dashboard), « BV » ambre, « Double » couleur
 * primaire… — la couleur est un raccourci de statut, pas une décoration.
 */
export const TILE_TONES = {
  violet: {
    tile: 'bg-violet-50 border-violet-200/80 dark:bg-violet-500/10 dark:border-violet-400/25',
    chip: 'bg-violet-600',
    text: 'text-violet-700 dark:text-violet-200',
  },
  emerald: {
    tile: 'bg-emerald-50 border-emerald-200/80 dark:bg-emerald-500/10 dark:border-emerald-400/25',
    chip: 'bg-emerald-500',
    text: 'text-emerald-700 dark:text-emerald-300',
  },
  blue: {
    tile: 'bg-blue-50 border-blue-200/80 dark:bg-blue-500/10 dark:border-blue-400/25',
    chip: 'bg-blue-500',
    text: 'text-blue-700 dark:text-blue-300',
  },
  rose: {
    tile: 'bg-rose-50 border-rose-200/80 dark:bg-rose-500/10 dark:border-rose-400/25',
    chip: 'bg-rose-500',
    text: 'text-rose-700 dark:text-rose-300',
  },
  amber: {
    tile: 'bg-amber-50 border-amber-200/80 dark:bg-amber-500/10 dark:border-amber-400/25',
    chip: 'bg-amber-500',
    text: 'text-amber-700 dark:text-amber-300',
  },
  indigo: {
    tile: 'bg-indigo-50 border-indigo-200/80 dark:bg-indigo-500/10 dark:border-indigo-400/25',
    chip: 'bg-indigo-500',
    text: 'text-indigo-700 dark:text-indigo-300',
  },
  fuchsia: {
    tile: 'bg-fuchsia-50 border-fuchsia-200/80 dark:bg-fuchsia-500/10 dark:border-fuchsia-400/25',
    chip: 'bg-fuchsia-600',
    text: 'text-fuchsia-700 dark:text-fuchsia-300',
  },
  cyan: {
    tile: 'bg-cyan-50 border-cyan-200/80 dark:bg-cyan-500/10 dark:border-cyan-400/25',
    chip: 'bg-cyan-500',
    text: 'text-cyan-700 dark:text-cyan-300',
  },
  slate: {
    tile: 'bg-slate-100 border-slate-300/70 dark:bg-slate-400/10 dark:border-slate-300/20',
    chip: 'bg-slate-900 text-slate-50 dark:bg-slate-200 dark:text-slate-900',
    text: 'text-slate-900 dark:text-slate-100',
  },
  red: {
    tile: 'bg-red-50 border-red-200/80 dark:bg-red-500/10 dark:border-red-400/25',
    chip: 'bg-red-500',
    text: 'text-red-700 dark:text-red-300',
  },
  // Couleur primaire de l'app (le « Double » des badges).
  brand: {
    tile: 'bg-primary/5 border-primary/25 dark:bg-primary/10 dark:border-primary/30',
    chip: 'bg-primary',
    text: 'text-primary dark:text-primary-foreground',
  },
}
