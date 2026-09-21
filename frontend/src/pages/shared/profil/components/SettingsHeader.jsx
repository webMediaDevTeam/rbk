import { ChevronRight, Home } from 'lucide-react'
import { useSettingsHeader } from './useSettingsHeader.js'

export default function SettingsHeader() {
  const { handleHomeClick } = useSettingsHeader()

  return (
    <>
      <nav className="flex items-center gap-1.5 text-sm text-muted-foreground">
        <a href="#" onClick={handleHomeClick} className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
          <Home className="h-3.5 w-3.5" />
          Accueil
        </a>
        <ChevronRight className="h-3.5 w-3.5" />
        <span className="font-medium text-foreground">Paramètres</span>
      </nav>

      <div className="border-b border-border pb-5">
        <h1 className="text-2xl font-bold tracking-tight text-foreground">Paramètres</h1>
        <p className="text-sm text-muted-foreground mt-1">
          Gérez votre profil, la sécurité de votre compte et vos préférences.
        </p>
      </div>
    </>
  )
}