import { Routes, Route, Navigate } from 'react-router-dom'
import ProtectedRoute, { ROLES } from '@/components/ProtectedRoute.jsx'
import DashboardPage from '@/pages/shared/dashboard/index.jsx'
import ProfilPage from '@/pages/shared/profil/index.jsx'
import EntrepriseListPage from '@/pages/shared/entrepriseList/index.jsx'
import EntrepriseDetailPage from '@/pages/shared/entrepriseDetail/index.jsx'
import ComercialListPage from '@/pages/shared/comercialList/index.jsx'
import AdminListPage from '@/pages/superAdmin/adminList/index.jsx'
import VerifyAccountPage from '@/pages/shared/verify-account/index.jsx'
import ConnexionPage from '@/pages/shared/connexion/index.jsx'
import UnauthorizedPage from '@/pages/shared/unauthorized/index.jsx'
import ProspectListPage from '@/pages/comercial/ProspectList/index.jsx'
import ClientDetailPage from '@/pages/shared/ClientDetail/index.jsx'
import MesListesPage from '@/pages/comercial/MesListes/index.jsx'
import GroupDetailPage from '@/pages/comercial/MesListes/GroupDetail.jsx'
import RemindersPage from '@/pages/comercial/Reminders/index.jsx'
import AutoRappelsPage from '@/pages/comercial/AutoRappels/index.jsx'
import ComercialDetailPage from '@/pages/shared/comercialDetail/index.jsx'
import ClientsHistoryPage from '@/pages/shared/clientsHistory/index.jsx'

// Association rôle ↔ route, calquée sur le middleware `CheckRole` du backend
// (voir backend/routes/api/*.php) : le garde frontend reflète les droits API
// pour éviter les écrans qui échouent en 403, sans jamais s'y substituer.
const guard = (allowedRoles, element) => (
  <ProtectedRoute allowedRoles={allowedRoles}>{element}</ProtectedRoute>
)

export default function AppRoutes() {
  return (
    <Routes>
      <Route path="/" element={<Navigate to="/dashboard" replace />} />

      {/* Toutes les rôles — routes/api/shared.php (auth:sanctum) */}
      <Route path="/dashboard" element={guard(ROLES.ALL, <DashboardPage />)} />
      <Route path="/profil" element={guard(ROLES.ALL, <ProfilPage />)} />
      <Route path="/unauthorized" element={guard(ROLES.ALL, <UnauthorizedPage />)} />

      {/* ADMIN + SUPER_ADMIN — routes/api/entreprise.php + admin.php */}
      <Route path="/entreprises" element={guard(ROLES.MANAGERS, <EntrepriseListPage />)} />
      <Route path="/entreprises/:id" element={guard(ROLES.MANAGERS, <EntrepriseDetailPage />)} />
      <Route path="/commerciaux" element={guard(ROLES.MANAGERS, <ComercialListPage />)} />
      <Route path="/comercialDetail/:id" element={guard(ROLES.MANAGERS, <ComercialDetailPage />)} />
      <Route path="/clients-historique" element={guard(ROLES.MANAGERS, <ClientsHistoryPage />)} />

      {/* Fiche client **partagée** (pages/shared) — `ROLES.ALL` : le
          composant est role-aware (`useClientDetail` bascule l'API selon le
          rôle : `useCommercialProspect` pour COMERCIAL, `useAdminClientDetail`
          pour ADMIN/SUPER_ADMIN), et les pages gestionnaires (clients-
          historique, fiche entreprise, fiche commerciale) y mènent par un
          lien « Voir ». Le garde reste en écho aux middleware `CheckRole` du
          backend, qui restent la source de vérité côté API. */}
      <Route path="/prospects/:id" element={guard(ROLES.ALL, <ClientDetailPage />)} />

      {/* COMERCIAL uniquement — routes/api/commercial.php (CheckRole:COMERCIAL) */}
      <Route path="/prospects" element={guard(ROLES.COMERCIAL, <ProspectListPage />)} />
      <Route path="/mes-listes" element={guard(ROLES.COMERCIAL, <MesListesPage />)} />
      <Route path="/mes-listes/:id" element={guard(ROLES.COMERCIAL, <GroupDetailPage />)} />
      <Route path="/reminders" element={guard(ROLES.COMERCIAL, <RemindersPage />)} />
      <Route path="/auto-rappels" element={guard(ROLES.COMERCIAL, <AutoRappelsPage />)} />

      {/* SUPER_ADMIN uniquement — routes/api/superAdmin.php + shared.php */}
      <Route path="/admins" element={guard(ROLES.SUPER_ADMIN, <AdminListPage />)} />

      {/* Pages hôte (déjà filtrées par GuestRoute dans App.jsx) */}
      <Route path="/connexion" element={<ConnexionPage />} />
      <Route path="/verify-account" element={<VerifyAccountPage />} />

      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Routes>
  )
}
