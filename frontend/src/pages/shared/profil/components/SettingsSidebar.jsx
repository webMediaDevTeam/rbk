import { useSettingsSidebar } from './useSettingsSidebar.js'

export const ROLE_LABELS = {
  SUPER_ADMIN: 'Super Admin',
  ADMIN: 'Admin',
  COMERCIAL: 'Employé',
}

export { getProfileTabs } from './useSettingsSidebar.js'

export default function SettingsSidebar({ role, activeTab, setActiveTab }) {
  const { tabs } = useSettingsSidebar({ role, activeTab, setActiveTab })

  return (
    <nav className="flex w-full lg:w-64 flex-shrink-0 items-stretch gap-1.5 lg:flex-col lg:gap-1 overflow-x-auto lg:overflow-visible pb-1 lg:pb-0 -mx-1 lg:mx-0 px-1 lg:px-0">
      {tabs.map((item) => {
        const Icon = item.icon
        return (
          <button
            key={item.id}
            onClick={item.onClick}
            className={item.className}
          >
            <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />
            <span>{item.label}</span>
          </button>
        )
      })}
    </nav>
  )
}