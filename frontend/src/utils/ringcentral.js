/**
 * Libellés des appareils RingCentral — partagés par les modales employé
 * (select « Appareil / numéro source »).
 *
 * `GET /call-logs/devices?enterprise_id=…` renvoie chaque appareil enrichi de
 * `phoneNumbers` (numéros de son extension, cf. `RingCentralController::
 * withPhoneNumbers`) ; les softphones, eux, ont toujours `phoneLines: []`.
 */

/** Numéros d'un appareil : ses lignes puis ceux de son extension (dé-doublonnés). */
export const devicePhones = (d) =>
  [...new Set([
    ...(d?.phoneLines ?? []).map((l) => l?.phoneNumber).filter(Boolean),
    ...(d?.phoneNumbers ?? []).filter(Boolean),
  ])]

/** Every device and each number attached to it becomes an explicit choice. */
export const ringcentralDeviceOptions = (devices) =>
  (Array.isArray(devices) ? devices : []).flatMap((device) => {
    const numbers = devicePhones(device)
    const choices = numbers.length ? numbers : ['']

    return choices.map((phoneNumber, index) => ({
      value: `${device?.id ?? 'device'}:${index}`,
      deviceId: String(device?.id ?? ''),
      phoneNumber,
      device,
      label: [phoneNumber, device?.name].filter(Boolean).join(' — ') || String(device?.id ?? ''),
    }))
  })

export const selectedRingcentralDeviceOption = (options, deviceId, phoneNumber) =>
  options.find((option) =>
    option.deviceId === String(deviceId ?? '')
    && option.phoneNumber === String(phoneNumber ?? '')
  )?.value ?? ''
