import { useEffect, useState } from 'react'
import { checkUsernameAvailabilityApi } from '@/api/shared.api.js'

/** Longueurs et syntaxe — miroir des règles `users.username` côté API. */
export const USERNAME_MIN = 3
export const USERNAME_MAX = 100

const USERNAME_SYNTAX = /^[A-Za-z0-9._-]+$/
const USERNAME_INVALID_CHARS = /[^A-Za-z0-9._-]/g

/** Ne garde que les caractères autorisés (saisie déjà propre à l'écran). */
export function sanitizeUsername(value) {
  return String(value ?? '').replace(USERNAME_INVALID_CHARS, '').slice(0, USERNAME_MAX)
}

/** Partie avant « @ » de l'e-mail → nom d'utilisateur proposé. */
export function usernameFromEmail(email) {
  return sanitizeUsername(String(email ?? '').split('@')[0])
}

/**
 * Contrôle en direct de la disponibilité d'un login (nom d'utilisateur).
 *
 * `users.username` est unique : on attend `delay` ms (300, court pour
 * rester « en direct » pendant la frappe sans marteler l'API) puis on
 * interroge `GET users/username-available` (l'employé édité étant exclu
 * via `exceptId`). Dès la première frappe, `checking` passe à `true` et
 * le message « Vérification… » s'affiche ; les réponses périmées sont
 * ignorées.
 *
 * @param {string} username valeur courante du champ
 * @param {{ exceptId?: string|null, delay?: number }} [options]
 * @returns {{
 *   value: string, empty: boolean, valid: boolean,
 *   checking: boolean, taken: boolean, available: boolean,
 *   message: string|null, tone: 'error'|'success'|'muted',
 * }}
 */
export function useUsernameAvailability(username, { exceptId = null, delay = 300 } = {}) {
  // Dernier contrôle distant : `{value, status}` (`value` = nom contrôlé).
  const [remote, setRemote] = useState({ value: null, status: 'unknown' })

  const value = String(username ?? '').trim()
  const key = value.toLowerCase()
  const empty = key === ''
  const tooShort = !empty && value.length < USERNAME_MIN
  const tooLong = value.length > USERNAME_MAX
  const badChars = !empty && !USERNAME_SYNTAX.test(value)
  // Champ vide ou syntaxe correcte : la syntaxe seule est jugeable
  // localement, l'unicité ne l'est que côté API.
  const valid = empty || (!tooShort && !tooLong && !badChars)

  useEffect(() => {
    if (!valid || empty) return undefined

    let cancelled = false

    const timer = setTimeout(() => {
      checkUsernameAvailabilityApi(key, exceptId)
        .then((data) => {
          if (cancelled) return
          const payload = data?.data ?? {}
          setRemote({ value: key, status: payload.available === true ? 'available' : 'taken' })
        })
        .catch(() => {
          if (cancelled) return
          // API injoignable : on ne bloque pas la saisie, on se tait.
          setRemote({ value: key, status: 'unknown' })
        })
    }, delay)

    return () => {
      cancelled = true
      clearTimeout(timer)
    }
  }, [key, valid, empty, exceptId, delay])

  const settled = remote.value === key
  const checking = valid && !empty && !settled
  const taken = valid && settled && remote.status === 'taken'
  const available = valid && settled && remote.status === 'available'

  let message = null
  let tone = 'muted'

  if (tooShort) {
    message = `${USERNAME_MIN} caractères minimum.`
    tone = 'error'
  } else if (tooLong) {
    message = `${USERNAME_MAX} caractères maximum.`
    tone = 'error'
  } else if (badChars) {
    message = 'Lettres, chiffres, points, tirets et tirets bas uniquement.'
    tone = 'error'
  } else if (taken) {
    message = 'Ce login est déjà pris.'
    tone = 'error'
  } else if (checking) {
    message = 'Vérification de la disponibilité…'
  } else if (available) {
    message = 'Login disponible.'
    tone = 'success'
  } else if (settled) {
    message = 'Vérification impossible pour le moment.'
  }

  return { value, empty, valid, checking, taken, available, message, tone }
}
