import { Navigate, useLocation } from 'react-router-dom'
import { useAuth } from '@/context/AuthContext.jsx'
import { getToken } from '@/lib/auth.js'
import { LOGIN_PATH, UNAUTHORIZED_PATH, resolveGuardAccess } from '@/lib/guard.js'

// Rôles applicatifs — mêmes valeurs que `App.jsx` (ROLE_NAV), que
// `lib/auth.js` (ROLE_RANK / PERMISSIONS) et que le middleware backend
// `CheckRole` (in_array strict sur `users.role`).
// Il n'existe pas de rôle « client » dans ce projet : le portail client est
// un import externe (webhook n8n → routes publiques de `shared.php`).
export const ROLES = {
  // Toute personne connectée (dashboard, profil).
  ALL: ['SUPER_ADMIN', 'ADMIN', 'COMERCIAL'],
  // Hiérarchie de gestion : entreprises, employés, grande liste, blacklist.
  MANAGERS: ['SUPER_ADMIN', 'ADMIN'],
  // Administration des admins (route backend `POST admins`).
  SUPER_ADMIN: ['SUPER_ADMIN'],
  // Espace prospect : réservations, rappels, issues d'appel.
  COMERCIAL: ['COMERCIAL'],
}

/**
 * Garde de route (authentification + rôle).
 *
 * 1. Pas d'utilisateur ou pas de token → `/connexion` (state.from conservé
 *    pour y retourner après le login).
 * 2. `allowedRoles` fourni et rôle absent de la liste → `/unauthorized`
 *    (rôle réel et rôles attendus passés en state pour l'affichage).
 * 3. Sinon → rend les enfants.
 *
 * Décision déportée dans `lib/guard.js` (`resolveGuardAccess`) pour rester
 * testable sans DOM — `scripts/test-protected-route.mjs`.
 *
 * C'est le complément (défense en profondeur) du middleware `CheckRole` :
 * l'API reste la source de vérité, ce garde évite seulement les écrans cassés.
 */
export default function ProtectedRoute({ allowedRoles, children }) {
  const { user, role, isAuthenticated } = useAuth()
  const location = useLocation()

  const decision = resolveGuardAccess({
    isAuthenticated,
    hasUser: Boolean(user),
    hasToken: Boolean(getToken()),
    role,
    allowedRoles,
  })

  if (decision.action === 'login') {
    return <Navigate to={LOGIN_PATH} replace state={{ from: location }} />
  }

  if (decision.action === 'unauthorized') {
    return (
      <Navigate
        to={UNAUTHORIZED_PATH}
        replace
        state={{ from: location, role: decision.role, allowedRoles: decision.allowedRoles }}
      />
    )
  }

  return children
}
