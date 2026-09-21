import Badge from '@/components/ui/badge.jsx'
import { useTopCommercialsCard } from './useTopCommercialsCard.js'

export default function TopCommercialsCard({ commercials = [] }) {
  const { items } = useTopCommercialsCard({ commercials })

  return (
    <div className="rounded-2xl bg-card p-6 text-card-foreground shadow-sm">
      <h3 className="text-base font-semibold">Meilleurs commerciaux</h3>
      <p className="text-sm text-muted-foreground">Classés par clients convertis (OUI)</p>

      <ul className="mt-5 space-y-5">
        {items.length === 0 ? (
          <li className="text-sm text-muted-foreground">Aucune donnée pour le moment.</li>
        ) : (
          items.map((commercial) => (
            <li key={commercial.id} className="space-y-2">
              <div className="flex items-center gap-3">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold text-muted-foreground">
                  {commercial.initials}
                </span>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{commercial.name}</p>
                  <p className="truncate text-xs text-muted-foreground">{commercial.email}</p>
                </div>
                <Badge variant="success">{commercial.clients_oui} OUI</Badge>
              </div>
              <div className="flex items-center gap-3">
                <div className="h-2 flex-1 rounded-full bg-muted">
                  <div className="h-2 rounded-full bg-primary" style={{ width: `${commercial.pct}%` }} />
                </div>
                <span className="text-xs text-muted-foreground tabular-nums">
                  {commercial.calls} appels
                </span>
              </div>
            </li>
          ))
        )}
      </ul>
    </div>
  )
}
