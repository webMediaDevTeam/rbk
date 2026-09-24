import { Routes, Route, Navigate } from 'react-router-dom'
import DashboardPage from '@/pages/shared/dashboard/index.jsx'
import ProfilPage from '@/pages/shared/profil/index.jsx'
import EntrepriseListPage from '@/pages/shared/entrepriseList/index.jsx'
import ComercialListPage from '@/pages/shared/comercialList/index.jsx'
import AdminListPage from '@/pages/superAdmin/adminList/index.jsx'
import VerifyAccountPage from '@/pages/shared/verify-account/index.jsx'
import ConnexionPage from '@/pages/shared/connexion/index.jsx'
import ProspectListPage from '@/pages/comercial/ProspectList/index.jsx'
import ClientDetailPage from '@/pages/comercial/ClientDetail/index.jsx'
import MesListesPage from '@/pages/comercial/MesListes/index.jsx'
import GroupDetailPage from '@/pages/comercial/MesListes/GroupDetail.jsx'
import RemindersPage from '@/pages/comercial/Reminders/index.jsx'
import AutoRappelsPage from '@/pages/comercial/AutoRappels/index.jsx'
import ComercialDetailPage from '@/pages/shared/comercialDetail/index.jsx'
import ClientsHistoryPage from '@/pages/shared/clientsHistory/index.jsx'
export default function AppRoutes() {
  return (
    <Routes>
      <Route path="/" element={<Navigate to="/dashboard" replace />} />
      <Route path="/dashboard" element={<DashboardPage />} />
      <Route path="/profil" element={<ProfilPage />} />
      <Route path="/entreprises" element={<EntrepriseListPage />} />
      <Route path="/commerciaux" element={<ComercialListPage />} />
      <Route path="/comercialDetail/:id" element={<ComercialDetailPage />} />
      <Route path="/clients-historique" element={<ClientsHistoryPage />} />
      <Route path="/prospects" element={<ProspectListPage />} />
      <Route path="/prospects/:id" element={<ClientDetailPage />} />
      <Route path="/mes-listes" element={<MesListesPage />} />
      <Route path="/mes-listes/:id" element={<GroupDetailPage />} />
      <Route path="/reminders" element={<RemindersPage />} />
      <Route path="/auto-rappels" element={<AutoRappelsPage />} />
      <Route path="/admins" element={<AdminListPage />} />
      <Route path="/connexion" element={<ConnexionPage />} />
      <Route path="/verify-account" element={<VerifyAccountPage />} />
      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Routes>
  )
}