/**
 * Geolocation du navigateur pour le contrôle de zone à la connexion.
 *
 * Enveloppe `navigator.geolocation.getCurrentPosition` en Promise avec un
 * délai maximum. **Jamais bloquante** : si la géolocalisation est indisponible
 * (contexte non sécurisé — HTTP), refusée par l'utilisateur ou en timeout,
 * on résout `null` et la requête de connexion part sans coordonnées ; le
 * serveur décide alors de bloquer ou non selon le rôle (voir
 * `GeofenceService`). Les gestionnaires ne sont jamais concernés.
 */
export function getPosition({ timeoutMs = 10000 } = {}) {
  if (typeof navigator === 'undefined' || !navigator.geolocation) {
    return Promise.resolve(null)
  }

  return new Promise((resolve) => {
    let settled = false
    const done = (value) => {
      if (settled) return
      settled = true
      resolve(value)
    }

    // Filet de sécurité : certains navigateurs n'appellent ni success ni
    // error si l'utilisateur laisse la modale ouverte.
    const guard = setTimeout(() => done(null), timeoutMs + 1000)

    navigator.geolocation.getCurrentPosition(
      (position) => {
        clearTimeout(guard)
        const { latitude, longitude, accuracy } = position.coords
        done({
          lat: latitude,
          lng: longitude,
          accuracy: typeof accuracy === 'number' ? accuracy : null,
        })
      },
      () => {
        clearTimeout(guard)
        done(null)
      },
      { enableHighAccuracy: true, timeout: timeoutMs, maximumAge: 0 },
    )
  })
}
