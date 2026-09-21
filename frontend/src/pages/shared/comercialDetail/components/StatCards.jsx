import Badge from '@/components/ui/badge.jsx'
import { useStatCards } from './useStatCards.js'

export default function StatCards(props) {
  const { stats } = useStatCards(props)

  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      {stats.map(({ label, value, icon: Icon, iconClass, badge }) => (
        <div
          key={label}
          className="group relative overflow-hidden rounded-2xl border border-border/60 bg-card p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-md"
        >
          <div className="flex items-start justify-between gap-3">
            <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ${iconClass}`}>
              <Icon className="h-5 w-5" />
            </div>
            {badge && (
              <Badge variant={badge.variant} className="shrink-0">
                {badge.text}
              </Badge>
            )}
          </div>

          <div className="mt-4 space-y-1">
            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
              {label}
            </p>
            <p className="text-3xl font-bold tracking-tight tabular-nums text-foreground">
              {value}
            </p>
          </div>
        </div>
      ))}
    </div>
  )
}