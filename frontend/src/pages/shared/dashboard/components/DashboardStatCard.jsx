import Badge from '@/components/ui/badge.jsx'

/**
 * Carte KPI du tableau de bord. Cliquable quand `onClick` est fourni : la
 * carte mène à la section du chiffre (Grande liste filtrée par le statut de
 * l'appel, page équivalente…), avec le curseur main et un anneau de focus.
 * `title` documente la destination au survol.
 */
export default function DashboardStatCard({ label, value, icon: Icon, iconClass, badge, onClick, title }) {
  const content = (
    <>
      <div className="flex items-start justify-between gap-3">
        <span className={`flex h-11 w-11 items-center justify-center rounded-xl ${iconClass}`}>
          <Icon className="h-5 w-5" />
        </span>
        {badge ? <Badge variant={badge.variant}>{badge.text}</Badge> : null}
      </div>
      <div className="mt-4 space-y-1">
        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
        <p className="text-3xl font-bold tracking-tight tabular-nums">{value}</p>
      </div>
    </>
  )

  if (!onClick) {
    return (
      <div className="rounded-2xl bg-card p-5 text-card-foreground shadow-sm">{content}</div>
    )
  }

  return (
    <button
      type="button"
      onClick={onClick}
      title={title}
      className="w-full cursor-pointer rounded-2xl bg-card p-5 text-left text-card-foreground shadow-sm transition-shadow hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      {content}
    </button>
  )
}
