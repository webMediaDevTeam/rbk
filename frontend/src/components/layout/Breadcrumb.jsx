import { ChevronRight, Home } from 'lucide-react'

const pageLabels = {
  dashboard: 'Dashboard',
  users: 'Users',
  profil: 'Profile',
  connexion: 'Connexion',
  comercial: 'Comercial',
  entreprise: 'Entreprise',
  admin: 'Admin',
  superAdmin: 'Super Admin',
  settings: 'Settings',
  help: 'Help Center',
}

export default function Breadcrumb({ activePage }) {
  const label = pageLabels[activePage] || activePage

  return (
    <nav className="flex items-center gap-1.5 text-sm text-muted-foreground mb-4">
      <a
        href="#"
        onClick={(e) => {
          e.preventDefault()
        }}
        className="inline-flex items-center gap-1 hover:text-foreground transition-colors"
      >
        <Home className="h-3.5 w-3.5" />
        Home
      </a>
      <ChevronRight className="h-3.5 w-3.5" />
      <span className="font-medium text-foreground">{label}</span>
    </nav>
  )
}