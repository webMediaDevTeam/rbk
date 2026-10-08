/**
 * Libellés des appareils RingCentral — partagés par les modales employé
 * (select « Appareil / numéro source »).
 *
 * `GET /call-logs/devices` renvoie chaque appareil enrichi de
 * `phoneNumbers` (numéros de son extension, cf. `RingCentralController::
 * withPhoneNumbers`) ; les softphones, eux, ont toujours `phoneLines: []`.
 */

/** Numéros d'un appareil : ses lignes puis ceux de son extension (dé-doublonnés). */
export const devicePhones = (d) =>
  [...new Set([
    ...(d?.phoneLines ?? []).map((l) => l?.phoneNumber).filter(Boolean),
    ...(d?.phoneNumbers ?? []).filter(Boolean),
  ])]

/** Premier numéro de l'appareil — la valeur stockée dans `ringcentral_from_number`. */
export const deviceNumber = (d) => devicePhones(d)[0] ?? ''

/** Libellé de l'option : **le numéro**, le nom de l'appareil en secours. */
export const deviceLabel = (d) => devicePhones(d).join(' · ') || d?.name || d?.id || ''

/**
 * Liste de la sélection « Appareil / numéro source ».
 *
 * Le compte RingCentral expose plusieurs appareils par numéro (deskphone,
 * softphone, « Existing Phone »…) : sans filtre, la liste répète chaque
 * numéro et affiche des noms d'appareils. On garde donc, **dans l'ordre
 * d'origine** :
 *
 *   - les appareils **porteurs d'un numéro** (un poste sans ligne ne peut
 *     pas être « numéro source ») — sauf si aucun appareil n'en a, pour ne
 *     jamais vider la liste ;
 *   - une **occurrence unique par libellé** (donc par numéro) ;
 *   - l'appareil **déjà enregistré** (`selectedId`) en priorité : il reste
 *     affiché à sa place, même quand il partage son numéro avec un doublon.
 *
 * @param {Array} [devices] appareils enrichis (`phoneLines`/`phoneNumbers`)
 * @param {string|null} [selectedId] appareil déjà choisi (édition)
 * @returns {Array} appareils à afficher
 */
export const selectableDevices = (devices, selectedId = null) => {
  const list = Array.isArray(devices) ? devices : []
  const id = selectedId === null || selectedId === undefined || selectedId === ''
    ? null
    : String(selectedId)
  const isSelected = (d) => id !== null && String(d.id) === id
  const hasNumber = (d) => devicePhones(d).length > 0
  const anyNumbered = list.some(hasNumber)
  const selectedDevice = id === null ? null : list.find(isSelected)
  const selectedLabel = selectedDevice ? deviceLabel(selectedDevice) : null

  const seen = new Set()

  return list.filter((d) => {
    const label = deviceLabel(d)
    if (!label) return false
    if (anyNumbered && !hasNumber(d) && !isSelected(d)) return false
    if (isSelected(d)) {
      seen.add(label)
      return true
    }
    if (label === selectedLabel || seen.has(label)) return false
    seen.add(label)
    return true
  })
}
