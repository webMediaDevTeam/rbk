import { useCallback, useSyncExternalStore } from 'react'

/**
 * Préférence « source » (origine du prospect, docs/RULES.md §13) **partagée et
 * persistée** : une seule clé de `localStorage` pilote à la fois
 *
 *  - les onglets « Source » de la Grande liste admin (`/clients-historique`),
 *  - le sélecteur « Source » et les compteurs du tableau de bord gestionnaire.
 *
 * Le choix survit au rechargement de la page et se propage d'un écran à
 * l'autre (y compris entre deux onglets du navigateur) : les pages lisent
 * **toujours** la valeur courante au montage, donc un filtre posé sur la liste
 * se retrouve tel quel dans le dashboard, et inversement.
 *
 * `''` = « Toutes » (aucun `?source=` envoyé, aucun badge actif). Valeur
 * purement **UI** : le serveur re-vérifie le privilège
 * (`clients:filter-source` côté ADMIN / SUPER_ADMIN seulement) et ignore le
 * paramètre pour les autres rôles.
 *
 * `localStorage` est inaccessible en mode privé / stockage saturé : la
 * préférence devient alors simplement in-memory (la valeur du moment), sans
 * erreur remontée à l'utilisateur.
 */
const STORAGE_KEY = 'rbk:clients-source'

/** Abonnés du store (composants montés) — pour la propagation intra-onglet. */
const listeners = new Set()

/** Repli mémoire quand `localStorage` refuse d'écrire (mode privé). */
let memoryFallback = null

function readSource() {
  if (typeof window === 'undefined') return ''
  try {
    return window.localStorage.getItem(STORAGE_KEY) ?? ''
  } catch {
    return ''
  }
}

function emit() {
  listeners.forEach((listener) => listener())
}

function onStorageEvent(event) {
  // `key === null` : `localStorage.clear()` appelé ailleurs dans la page.
  if (event.key === STORAGE_KEY || event.key === null) emit()
}

function writeSource(value) {
  const next = value || ''
  try {
    if (next) window.localStorage.setItem(STORAGE_KEY, next)
    else window.localStorage.removeItem(STORAGE_KEY)
    memoryFallback = null
  } catch {
    // Stockage indisponible : on garde la valeur pour la session en cours.
    memoryFallback = next
  }
  emit()
}

function subscribe(listener) {
  listeners.add(listener)
  if (listeners.size === 1 && typeof window !== 'undefined') {
    window.addEventListener('storage', onStorageEvent)
  }
  return () => {
    listeners.delete(listener)
    if (listeners.size === 0 && typeof window !== 'undefined') {
      window.removeEventListener('storage', onStorageEvent)
    }
  }
}

function getSnapshot() {
  if (memoryFallback !== null) {
    // L'écriture a échoué : c'est la valeur mémoire qui fait foi, sinon le
    // snapshot reviendrait à l'ancienne valeur et la sélection s'annulerait.
    return memoryFallback
  }
  return readSource()
}

export function useSourcePreference() {
  const source = useSyncExternalStore(subscribe, getSnapshot, () => '')

  const setSource = useCallback((value) => writeSource(value ?? ''), [])

  return { source, setSource }
}
