import { Loader2 } from 'lucide-react'
import SettingsHeader from './components/SettingsHeader.jsx'
import SettingsSidebar from './components/SettingsSidebar.jsx'
import AvatarSection from './components/AvatarSection.jsx'
import UserDataSection from './components/UserDataSection.jsx'
import SecuritySection from './components/SecuritySection.jsx'
import EmployeeDataSection from './components/EmployeeDataSection.jsx'
import ThemeSection from './components/ThemeSection.jsx'
import { useProfilPage } from './useProfil.js'

export default function ProfilPage() {
  const { activeTab, setActiveTab, showLoading, profile, role, queryKey } = useProfilPage()

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <SettingsHeader />

      <div className="flex flex-col lg:flex-row gap-10">
        <SettingsSidebar role={role} activeTab={activeTab} setActiveTab={setActiveTab} />

        <main className="flex-1">
          {showLoading && (
            <div className="flex items-center gap-2 text-sm text-muted-foreground py-8">
              <Loader2 className="h-4 w-4 animate-spin" />
              Chargement du profil…
            </div>
          )}

          {profile && (
            <>
              {activeTab === 'avatar' && <AvatarSection user={profile} queryKey={queryKey} />}
              {activeTab === 'user-data' && <UserDataSection user={profile} role={role} />}
              {activeTab === 'employee-data' && role === 'COMERCIAL' && <EmployeeDataSection user={profile} />}
              {activeTab === 'security' && <SecuritySection user={profile} />}
              {activeTab === 'appearance' && <ThemeSection />}
            </>
          )}
        </main>
      </div>
    </div>
  )
}