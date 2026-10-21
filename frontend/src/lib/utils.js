import { clsx } from 'clsx'
import { twMerge } from 'tailwind-merge'

export function cn(...inputs) {
  return twMerge(clsx(inputs))
}

export function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

/**
 * Raccourcit un libellé pour l'affichage dans un `<option>` natif : la largeur
 * du menu déroulant d'un `<select>` est dictée par son option la plus longue,
 * donc un libellé très long (ex. certaines catégories) fait déborder la liste à
 * l'écran. On tronque le **texte visible** (le `value` complet reste utilisé
 * pour le filtrage) et on garde le libellé entier dans l'attribut `title`.
 */
export function truncateLabel(text, max = 42) {
  const value = String(text ?? '')
  if (value.length <= max) return value
  return `${value.slice(0, max - 1).trimEnd()}…`
}
