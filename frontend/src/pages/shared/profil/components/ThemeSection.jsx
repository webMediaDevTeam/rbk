import { Check, Moon, Monitor, Sun } from 'lucide-react'
import { useTheme } from '@/context/theme-provider.jsx'

const THEME_OPTIONS = [
  { value: 'light', label: 'Clair', description: 'Utiliser un thème clair.', icon: Sun },
  { value: 'dark', label: 'Sombre', description: 'Utiliser un thème sombre.', icon: Moon },
  { value: 'system', label: 'Système', description: 'Suivre les préférences de votre appareil.', icon: Monitor },
]

export default function ThemeSection() {
  const { theme, setTheme } = useTheme()

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-lg font-semibold text-foreground">Apparence</h2>
        <p className="text-sm text-muted-foreground">
          Personnalisez l’apparence de l’application. Choisissez un thème clair, sombre ou automatique.
        </p>
      </div>

      <div className="border-b border-border" />

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
        {THEME_OPTIONS.map((option) => {
          const Icon = option.icon
          const isActive = theme === option.value
          return (
            <button
              key={option.value}
              type="button"
              onClick={() => setTheme(option.value)}
              className={`relative flex flex-col items-start gap-2 rounded-xl border p-4 text-left transition-colors ${
                isActive
                  ? 'border-primary bg-primary/5'
                  : 'border-border hover:border-ring hover:bg-muted/50'
              }`}
            >
              {isActive && (
                <span className="absolute top-3 right-3 flex h-5 w-5 items-center justify-center rounded-full bg-primary text-primary-foreground">
                  <Check className="h-3 w-3" />
                </span>
              )}
              <Icon className="h-5 w-5 text-muted-foreground" />
              <span className="text-sm font-semibold text-foreground">{option.label}</span>
              <span className="text-xs text-muted-foreground">{option.description}</span>
            </button>
          )
        })}
      </div>
    </div>
  )
}