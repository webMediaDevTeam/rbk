// Logique pure de la garde de route — volontairement séparée du JSX pour
// être testable sans DOM (voir `scripts/test-protected-route.mjs`).
//
// Les valeurs de rôle viennent de `lib/auth.js` (ROLE_RANK / PERMISSIONS) et
// doivent rester alignées sur le middleware backend `CheckRole`.

export const LOGIN_PATH = '/connexion'
export const UNAUTHORIZED_PATH = '/unauthorized'

/**
 * Décision d'accès pour une route protégée.
 *
 * @param {object}   input
 * @param {boolean}  input.isAuthenticated  session présente (AuthContext)
 * @param {boolean}  input.hasUser          utilisateur stocké
 * @param {boolean}  input.hasToken         token présent (local/session/cookie)
 * @param {string}   input.role             rôle courant
 * @param {string[]} [input.allowedRoles]   rôles autorisés (absent = tous)
 *
 * @returns {{action: 'login'}
 *         | {action: 'unauthorized', role: string, allowedRoles: string[]}
 *         | {action: 'allow', role: string}}
 */
export function resolveGuardAccess({ isAuthenticated, hasUser, hasToken, role, allowedRoles }) {
  // 1. Session incomplète → page de connexion.
  if (!isAuthenticated || !hasUser || !hasToken) {
    return { action: 'login' }
  }

  // 2. Liste de rôles vide ou absente → tout rôle authentifié suffit.
  if (!Array.isArray(allowedRoles) || allowedRoles.length === 0) {
    return { action: 'allow', role }
  }

  // 3. Rôle hors liste → page « accès refusé ».
  if (!allowedRoles.includes(role)) {
    return { action: 'unauthorized', role, allowedRoles }
  }

  return { action: 'allow', role }
}
