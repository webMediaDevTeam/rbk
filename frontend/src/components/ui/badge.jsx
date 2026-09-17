import { cn } from '../../lib/utils'

const variants = {
  default: 'bg-primary text-primary-foreground border-transparent',
  secondary: 'bg-muted text-muted-foreground border-transparent',
  outline: 'bg-transparent text-foreground border-border',
  destructive: 'bg-destructive/10 text-destructive border-destructive/20',
  success: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
  warning: 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
  info: 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20',
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