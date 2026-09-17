import { Camera, User, ShieldCheck, Building2, Briefcase, Palette } from 'lucide-react'
import { cn } from '../../../../lib/utils'

export const ROLE_LABELS = {
  SUPER_ADMIN: 'Super Admin',
  ADMIN: 'Admin',
  ENTREPRISE: 'Entreprise',
  COMERCIAL: 'Commercial',
}

export function getProfileTabs(role) {
  const tabs = [
    {
      id: 'avatar',
      label: role === 'ENTREPRISE' ? 'Logo & photo' : 'Photo',
      icon: Camera,
    },
    { id: 'user-data', label: 'Données utilisateur', icon: User },
   
  ]

  if (role === 'COMERCIAL') {
    tabs.push({ id: 'employee-data', label: 'Données employé', icon: Briefcase })
  }
  if (role === 'ENTREPRISE') {
    tabs.push({ id: 'enterprise-data', label: 'Données entreprise', icon: Building2 })
  }


tabs.push({ id: 'security', label: 'Sécurité & mot de passe', icon: ShieldCheck })
tabs.push({ id: 'appearance', label: 'Apparence', icon: Palette }) 

  return tabs
}

export default function SettingsSidebar({ role, activeTab, setActiveTab }) {
  const tabs = getProfileTabs(role)

  return (
    <nav className="flex w-full lg:w-64 flex-shrink-0 items-stretch gap-1.5 lg:flex-col lg:gap-1 overflow-x-auto lg:overflow-visible pb-1 lg:pb-0 -mx-1 lg:mx-0 px-1 lg:px-0">
      {tabs.map((item) => {
        const Icon = item.icon
        const isActive = activeTab === item.id
        return (
          <button
            key={item.id}
            onClick={() => setActiveTab(item.id)}
            className={cn(
              'flex items-center gap-2 lg:gap-3 rounded-lg px-3 py-2 lg:py-2.5 text-sm font-medium whitespace-nowrap transition-colors text-left',
              isActive
                ? 'bg-muted text-foreground font-semibold'
                : 'text-muted-foreground hover:bg-muted/50 hover:text-foreground'
            )}
          >
            <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />
            <span>{item.label}</span>
          </button>
        )
      })}
    </nav>
  )
}