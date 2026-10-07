// Vérification de l'enforcement des gardes de route (`ProtectedRoute`).
// Node pur, sans dépendance : `src/lib/guard.js` est du JS sans JSX.
//
//   node scripts/test-protected-route.mjs
//
// Couvre les 4 scénarios demandés :
//   1. non connecté sur une route protégée  → /connexion
//   2. connecté mais rôle insuffisant       → /unauthorized
//   3. connecté avec le rôle requis         → rendu de la page
//   4. rôle inconnu (GUEST)                 → /unauthorized

import assert from 'node:assert/strict'
import { LOGIN_PATH, UNAUTHORIZED_PATH, resolveGuardAccess } from '../src/lib/guard.js'

const MANAGERS = ['SUPER_ADMIN', 'ADMIN']
const ALL = ['SUPER_ADMIN', 'ADMIN', 'COMERCIAL']

let failures = 0

function check(label, fn) {
  try {
    fn()
    console.log(`  ✓ ${label}`)
  } catch (error) {
    failures += 1
    console.error(`  ✗ ${label}\n    ${error.message}`)
  }
}

console.log('1. Non connecté → /connexion')
check('aucune session', () => {
  assert.deepEqual(
    resolveGuardAccess({ isAuthenticated: false, hasUser: false, hasToken: false, role: 'GUEST', allowedRoles: ALL }),
    { action: 'login' }
  )
})
check('utilisateur sans token', () => {
  assert.deepEqual(
    resolveGuardAccess({ isAuthenticated: true, hasUser: true, hasToken: false, role: 'ADMIN', allowedRoles: ALL }),
    { action: 'login' }
  )
})
check('token sans utilisateur', () => {
  assert.deepEqual(
    resolveGuardAccess({ isAuthenticated: true, hasUser: false, hasToken: true, role: 'ADMIN', allowedRoles: ALL }),
    { action: 'login' }
  )
})
check('la session manquante prime sur le rôle (pas de fuite vers /unauthorized)', () => {
  assert.equal(
    resolveGuardAccess({ isAuthenticated: false, hasUser: false, hasToken: false, role: 'GUEST', allowedRoles: MANAGERS }).action,
    'login'
  )
})
assert.equal(LOGIN_PATH, '/connexion')

console.log('2. Connecté mais rôle insuffisant → /unauthorized')
check('COMERCIAL sur une route MANAGERS (/entreprises)', () => {
  const decision = resolveGuardAccess({
    isAuthenticated: true, hasUser: true, hasToken: true,
    role: 'COMERCIAL', allowedRoles: MANAGERS,
  })
  assert.deepEqual(decision, { action: 'unauthorized', role: 'COMERCIAL', allowedRoles: MANAGERS })
})
check('ADMIN sur une route SUPER_ADMIN (/admins)', () => {
  const decision = resolveGuardAccess({
    isAuthenticated: true, hasUser: true, hasToken: true,
    role: 'ADMIN', allowedRoles: ['SUPER_ADMIN'],
  })
  assert.equal(decision.action, 'unauthorized')
  assert.equal(decision.role, 'ADMIN')
})
check('COMERCIAL sur une route COMERCIAL-exclusive : ADMIN refusé (/prospects)', () => {
  const decision = resolveGuardAccess({
    isAuthenticated: true, hasUser: true, hasToken: true,
    role: 'ADMIN', allowedRoles: ['COMERCIAL'],
  })
  assert.equal(decision.action, 'unauthorized')
})
check('rôle inconnu (GUEST) sur une route protégée', () => {
  const decision = resolveGuardAccess({
    isAuthenticated: true, hasUser: true, hasToken: true,
    role: 'GUEST', allowedRoles: ALL,
  })
  assert.equal(decision.action, 'unauthorized')
})
assert.equal(UNAUTHORIZED_PATH, '/unauthorized')

console.log('3. Connecté avec le rôle requis → rendu')
check('ADMIN sur MANAGERS', () => {
  assert.deepEqual(
    resolveGuardAccess({ isAuthenticated: true, hasUser: true, hasToken: true, role: 'ADMIN', allowedRoles: MANAGERS }),
    { action: 'allow', role: 'ADMIN' }
  )
})
check('SUPER_ADMIN sur SUPER_ADMIN', () => {
  assert.equal(
    resolveGuardAccess({ isAuthenticated: true, hasUser: true, hasToken: true, role: 'SUPER_ADMIN', allowedRoles: ['SUPER_ADMIN'] }).action,
    'allow'
  )
})
check('COMERCIAL sur COMERCIAL (espace prospect)', () => {
  assert.equal(
    resolveGuardAccess({ isAuthenticated: true, hasUser: true, hasToken: true, role: 'COMERCIAL', allowedRoles: ['COMERCIAL'] }).action,
    'allow'
  )
})
check('chaque rôle sur ROLES.ALL (dashboard, profil)', () => {
  for (const role of ['SUPER_ADMIN', 'ADMIN', 'COMERCIAL']) {
    assert.equal(
      resolveGuardAccess({ isAuthenticated: true, hasUser: true, hasToken: true, role, allowedRoles: ALL }).action,
      'allow'
    )
  }
})
check('sans allowedRoles ni liste vide → tout rôle authentifié passe', () => {
  assert.equal(
    resolveGuardAccess({ isAuthenticated: true, hasUser: true, hasToken: true, role: 'COMERCIAL' }).action,
    'allow'
  )
  assert.equal(
    resolveGuardAccess({ isAuthenticated: true, hasUser: true, hasToken: true, role: 'COMERCIAL', allowedRoles: [] }).action,
    'allow'
  )
})

if (failures > 0) {
  console.error(`\n${failures} échec(s).`)
  process.exit(1)
}
console.log('\nTous les scénarios passent.')
