import Badge from '@/components/ui/badge.jsx'

export default function DashboardStatCard({ label, value, icon: Icon, iconClass, badge }) {
  return (
    <div className="rounded-2xl bg-card p-5 text-card-foreground shadow-sm transition-shadow hover:shadow-md">
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
    </div>
  )
}
