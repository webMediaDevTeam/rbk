import { Briefcase, Camera, Palette, ShieldCheck, User } from 'lucide-react'
import { cn } from '@/lib/utils'

export function getProfileTabs(role) {
  const tabs = [
    {
      id: 'avatar',
      label: 'Photo de profil',
      icon: Camera,
    },
    { id: 'user-data', label: 'Données utilisateur', icon: User },
  ]

  if (role === 'COMERCIAL') {
    tabs.push({ id: 'employee-data', label: 'Données employé', icon: Briefcase })
  }

  tabs.push({ id: 'security', label: 'Sécurité & mot de passe', icon: ShieldCheck })
  tabs.push({ id: 'appearance', label: 'Apparence', icon: Palette })

  return tabs
}

export function useSettingsSidebar({ role, activeTab, setActiveTab }) {
  const tabs = getProfileTabs(role).map((item) => ({
    ...item,
    isActive: activeTab === item.id,
    onClick: () => setActiveTab(item.id),
    className: cn(
      'flex items-center gap-2 lg:gap-3 rounded-lg px-3 py-2 lg:py-2.5 text-sm font-medium whitespace-nowrap transition-colors text-left',
      activeTab === item.id
        ? 'bg-muted text-foreground font-semibold'
        : 'text-muted-foreground hover:bg-muted/50 hover:text-foreground'
    ),
  }))

  return { tabs }
}