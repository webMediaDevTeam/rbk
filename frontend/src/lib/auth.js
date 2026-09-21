import { getCookie, removeCookie, setCookie } from './cookies.js'

export const TOKEN_KEY = 'rbk_token'
export const USER_KEY = 'rbk_user'
const REMEMBER_KEY = 'rbk_remember'
const TOKEN_COOKIE_MAX_AGE = 60 * 60 * 24 * 30

export const ROLE_HOME = {
  SUPER_ADMIN: '/dashboard',
  ADMIN: '/dashboard',
  COMERCIAL: '/dashboard',
}

export function homeForRole(role) {
  return ROLE_HOME[role] ?? 'dashboard'
}

// Role hierarchy: higher index = lower privilege
const ROLE_RANK = {
  SUPER_ADMIN: 0,
  ADMIN: 1,
  COMERCIAL: 2,
}

// Permission map: which roles can access which resources
export const PERMISSIONS = {
  // User management
  'users:list':     ['SUPER_ADMIN', 'ADMIN', 'COMERCIAL'],
  'users:create':   ['SUPER_ADMIN', 'ADMIN'],
  'users:update':   ['SUPER_ADMIN', 'ADMIN'],
  'users:delete':   ['SUPER_ADMIN', 'ADMIN'],

  // Entreprise management
  'entreprises:list':   ['SUPER_ADMIN', 'ADMIN'],
  'entreprises:create': ['SUPER_ADMIN', 'ADMIN'],
  'entreprises:update': ['SUPER_ADMIN', 'ADMIN'],
  'entreprises:delete': ['SUPER_ADMIN', 'ADMIN'],

  // Commercial management
  'commercials:list':   ['SUPER_ADMIN', 'ADMIN'],
  'commercials:create': ['SUPER_ADMIN', 'ADMIN'],
  'commercials:update': ['SUPER_ADMIN', 'ADMIN'],
  'commercials:delete': ['SUPER_ADMIN', 'ADMIN'],

  // Admin management
  'admins:list':   ['SUPER_ADMIN'],
  'admins:create': ['SUPER_ADMIN'],
  'admins:update': ['SUPER_ADMIN'],
  'admins:delete': ['SUPER_ADMIN'],

  // Blacklist
  'blacklist:list':    ['SUPER_ADMIN', 'ADMIN'],
  'blacklist:unblock': ['SUPER_ADMIN', 'ADMIN'],

  // Dashboard
  'dashboard': ['SUPER_ADMIN', 'ADMIN', 'COMERCIAL'],

  // Profil
  'profil': ['SUPER_ADMIN', 'ADMIN', 'COMERCIAL'],
}

export function hasPermission(role, permission) {
  const allowed = PERMISSIONS[permission]
  return allowed ? allowed.includes(role) : false
}

export function canAccess(role, resource) {
  return hasPermission(role, resource)
}

export function outranks(actorRole, targetRole) {
  return (ROLE_RANK[actorRole] ?? 99) < (ROLE_RANK[targetRole] ?? 99)
}

export function getToken() {
  if (typeof window === 'undefined') return null
  return window.localStorage.getItem(TOKEN_KEY)
    ?? window.sessionStorage.getItem(TOKEN_KEY)
    ?? getCookie(TOKEN_KEY)
}

export function getStoredUser() {
  if (typeof window === 'undefined') return null
  try {
    const raw = window.localStorage.getItem(USER_KEY) ?? window.sessionStorage.getItem(USER_KEY)
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

export function setSession({ utilisateur, jeton, remember = false }) {
  if (typeof window === 'undefined') return
  clearSession()

  const storage = remember ? window.localStorage : window.sessionStorage
  storage.setItem(TOKEN_KEY, jeton)
  storage.setItem(USER_KEY, JSON.stringify(utilisateur))
  storage.setItem(REMEMBER_KEY, remember ? '1' : '0')
  setCookie(TOKEN_KEY, jeton, remember ? { maxAge: TOKEN_COOKIE_MAX_AGE } : {})
}

export function clearSession() {
  if (typeof window === 'undefined') return
  window.localStorage.removeItem(TOKEN_KEY)
  window.localStorage.removeItem(USER_KEY)
  window.localStorage.removeItem(REMEMBER_KEY)
  window.sessionStorage.removeItem(TOKEN_KEY)
  window.sessionStorage.removeItem(USER_KEY)
  window.sessionStorage.removeItem(REMEMBER_KEY)
  removeCookie(TOKEN_KEY)
}
