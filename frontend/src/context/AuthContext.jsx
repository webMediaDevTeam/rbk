import { createContext, useCallback, useContext, useMemo, useState } from 'react'
import { clearSession, getStoredUser, setSession, homeForRole, hasPermission, canAccess, outranks } from '../lib/auth.js'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(() => getStoredUser())
  const isAuthenticated = Boolean(user)

  const login = useCallback(({ utilisateur, jeton, remember = false }) => {
    setSession({ utilisateur, jeton, remember })
    setUser(utilisateur)
  }, [])

  const logout = useCallback(() => {
    clearSession()
    setUser(null)
  }, [])

  const role = user?.role ?? 'GUEST'

  const value = useMemo(
    () => ({
      user,
      isAuthenticated,
      role,
      login,
      logout,
      homeForRole,
      hasPermission: (permission) => hasPermission(role, permission),
      canAccess: (resource) => canAccess(role, resource),
      outranks: (targetRole) => outranks(role, targetRole),
    }),
    [user, isAuthenticated, role, login, logout]
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within an AuthProvider')
  return ctx
}
