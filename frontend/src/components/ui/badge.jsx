import { cn } from '@/lib/utils'

const variants = {
  default: 'bg-primary text-primary-foreground border-transparent',
  secondary: 'bg-muted text-muted-foreground border-transparent',
  outline: 'bg-transparent text-foreground border-border',
  destructive: 'bg-destructive/10 text-destructive border-destructive/20',
  primary: 'bg-primary/10 text-primary border-primary/25',
  success: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
  warning: 'bg-amber-500/10 text-[var(--warning-fg)] border-amber-600/25',
  info: 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20',
  // « Liste noire » : pastille neutre sans couleur de statut — noir en mode
  // clair, gris en mode sombre (mêmes variables `--status-badge*` que le badge
  // `ClientStatus` et le bouton blacklist, pour garder la couleur cohérente).
  blacklist: 'bg-[var(--status-badge)] text-[var(--status-badge-foreground)] border-transparent',
  // Fiche « répétée » (doublon rattaché à son parent) : même teinte fuchsia
  // que la pastille de filtre « num Répétés » de la Grande liste.
  repeat: 'bg-fuchsia-500/10 text-fuchsia-600 dark:text-fuchsia-400 border-fuchsia-500/20',
}

export default function Badge({
  variant = 'default',
  className,
  ...props
}) {
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold transition-colors',
        variants[variant],
        className
      )}
      {...props}
    />
  )
}