import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { useAuth } from './context/AuthContext.jsx'
import Layout from './components/layout/Layout.jsx'
import AuthLayout from './components/layout/AuthLayout.jsx'
import ConnexionPage from './pages/shared/connexion/index.jsx'
import VerifyAccountPage from './pages/shared/verify-account/index.jsx'
import { Toaster } from './components/ui/sonner.jsx'
import AppRoutes from './routes/AppRoutes.jsx'
import {
  LayoutDashboard,
  UserRound,
  Building2,
  ShieldCheck,
  List,
  Bell,
} from 'lucide-react'

const ROLE_NAV = {
  SUPER_ADMIN: [
    {
      title: 'Principal',
      items: [
        { title: 'Dashboard', path: '/dashboard', icon: LayoutDashboard },
      ],
    },
    {
      title: 'Gestion',
      items: [
        { title: 'Entreprises', path: '/entreprises', icon: Building2 },
        { title: 'Commerciaux', path: '/commerciaux', icon: List },
        { title: 'Admins', path: '/admins', icon: ShieldCheck },
      ],
    },
    {
      title: 'Mon compte',
      items: [
        { title: 'Profil', path: '/profil', icon: UserRound },
      ],
    },
  ],
  ADMIN: [
    {
      title: 'Principal',
      items: [
        { title: 'Dashboard', path: '/dashboard', icon: LayoutDashboard },
      ],
    },
    {
      title: 'Gestion',
      items: [
        { title: 'Entreprises', path: '/entreprises', icon: Building2 },
        { title: 'Commerciaux', path: '/commerciaux', icon: List },
      ],
    },
    {
      title: 'Mon compte',
      items: [
        { title: 'Profil', path: '/profil', icon: UserRound },
      ],
    },
  ],
  ENTREPRISE: [
    {
      title: 'Principal',
      items: [
        { title: 'Dashboard', path: '/dashboard', icon: LayoutDashboard },
      ],
    },
    {
      title: 'Gestion',
      items: [
        { title: 'Commerciaux', path: '/commerciaux', icon: List },
      ],
    },
    {
      title: 'Mon compte',
      items: [
        { title: 'Profil', path: '/profil', icon: UserRound },
      ],
    },
  ],
  COMERCIAL: [
    {
      title: 'Principal',
      items: [
        { title: 'Dashboard', path: '/dashboard', icon: LayoutDashboard },
      ],
    },
    {
      title: 'Clients',
      items: [
        { title: 'Tous les clients', path: '/clients', icon: List },
        { title: 'Mes clients', path: '/mes-clients', icon: List },
        { title: 'Rappels', path: '/reminders', icon: Bell },
      ],
    },
    {
      title: 'Mon compte',
      items: [
        { title: 'Profil', path: '/profil', icon: UserRound },
      ],
    },
  ],
}

function ProtectedLayout() {
  const { role } = useAuth()
  const navGroups = ROLE_NAV[role] ?? ROLE_NAV.COMERCIAL
  return (
    <Layout navGroups={navGroups}>
      <AppRoutes />
    </Layout>
  )
}

function GuestRoute({ children }) {
  const { isAuthenticated } = useAuth()
  return isAuthenticated ? <Navigate to="/dashboard" replace /> : children
}

function VerifyAccountRoute({ children }) {
  const { isAuthenticated } = useAuth()
  return isAuthenticated ? <Navigate to="/dashboard" replace /> : children
}

function AuthRoute({ children }) {
  const { isAuthenticated } = useAuth()
  return isAuthenticated ? children : <Navigate to="/connexion" replace />
}

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route
          path="/connexion"
          element={
            <GuestRoute>
              <AuthLayout>
                <ConnexionPage />
              </AuthLayout>
            </GuestRoute>
          }
        />
        <Route
          path="/verify-account"
          element={
            <VerifyAccountRoute>
              <AuthLayout>
                <VerifyAccountPage />
              </AuthLayout>
            </VerifyAccountRoute>
          }
        />
        <Route
          path="/*"
          element={
            <AuthRoute>
              <ProtectedLayout />
            </AuthRoute>
          }
        />
      </Routes>
      <Toaster />
    </BrowserRouter>
  )
}
