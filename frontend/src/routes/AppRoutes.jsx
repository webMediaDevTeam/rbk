import { Routes, Route, Navigate } from 'react-router-dom'
import DashboardPage from '../pages/dashboard/index.jsx'
import ProfilPage from '../pages/shared/profil/index.jsx'
import EntrepriseListPage from '../pages/shared/entrepriseList/index.jsx'
import ComercialListPage from '../pages/shared/comercialList/index.jsx'
import AdminListPage from '../pages/superAdmin/adminList/index.jsx'
import VerifyAccountPage from '../pages/shared/verify-account/index.jsx'
import ConnexionPage from '../pages/shared/connexion/index.jsx'

export default function AppRoutes() {
  return (
    <Routes>
      <Route path="/" element={<Navigate to="/dashboard" replace />} />
      <Route path="/dashboard" element={<DashboardPage />} />
      <Route path="/profil" element={<ProfilPage />} />
      <Route path="/entreprises" element={<EntrepriseListPage />} />
      <Route path="/commerciaux" element={<ComercialListPage />} />
      <Route path="/admins" element={<AdminListPage />} />
      <Route path="/connexion" element={<ConnexionPage />} />
      <Route path="/verify-account" element={<VerifyAccountPage />} />
      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Routes>
  )
}