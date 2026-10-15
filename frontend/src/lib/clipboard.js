/**
 * Copie de texte robuste, pensée pour le contexte de dev : `navigator.clipboard`
 * n'existe que dans un **contexte sécurisé** (HTTPS ou localhost). Or les
 * conteneurs sont souvent atteints en HTTP via une IP de LAN — dans ce cas
 * `navigator.clipboard` vaut `undefined` et un appel direct échoue
 * **synchroniquement** (le `.catch()` du Promise ne se déclenche jamais).
 *
 * Ce helper :
 *  1. utilise l'API Clipboard quand elle est disponible ;
 *  2. retombe sur la technique legacy `<textarea>` + `document.execCommand('copy')`
 *     (seule option qui marche en contexte non sécurisé) ;
 *  3. renvoie toujours un `Promise<boolean>` — `true` si le texte a été copié.
 *
 * @param {string} text texte à copier
 * @returns {Promise<boolean>} réussite de la copie
 */
export async function copyText(text) {
  const value = text == null ? '' : String(text)
  if (!value) return false

  // 1. API Clipboard moderne (contexte sécurisé uniquement).
  if (navigator.clipboard && window.isSecureContext) {
    try {
      await navigator.clipboard.writeText(value)
      return true
    } catch {
      // On tente le repli legacy plutôt que d'échouer directement.
    }
  }

  // 2. Repli legacy : textarea hors écran + execCommand('copy').
  try {
    const textarea = document.createElement('textarea')
    textarea.value = value
    textarea.setAttribute('readonly', '')
    textarea.style.position = 'fixed'
    textarea.style.top = '0'
    textarea.style.left = '0'
    textarea.style.opacity = '0'
    textarea.style.pointerEvents = 'none'

    document.body.appendChild(textarea)
    textarea.select()
    textarea.setSelectionRange(0, textarea.value.length)

    const ok = document.execCommand('copy')
    document.body.removeChild(textarea)
    return ok
  } catch {
    return false
  }
}
