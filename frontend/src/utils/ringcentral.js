/**
 * Libellés des appareils RingCentral — partagés par la console d'appel
 * (`/call-logs-test`) et les modales employé (select « Appareil / numéro
 * source »).
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
