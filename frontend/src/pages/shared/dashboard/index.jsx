import { Loader2 } from 'lucide-react'
import { useDashboard } from './useDashboard.js'
import DashboardStatCard from './components/DashboardStatCard.jsx'
import CallsChart from './components/CallsChart.jsx'
import TopCommercialsCard from './components/TopCommercialsCard.jsx'

export default function DashboardPage() {
  const { isLoading, isError, isAdmin, cards, callsByDay, topCommercials } = useDashboard()

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Dashboard</h1>
        <p className="text-sm text-muted-foreground">
          {isAdmin
            ? 'Vue globale des entreprises, employés, prospects et appels'
            : 'Vos statistiques personnelles'}
        </p>
      </div>

      {isLoading ? (
        <div className="flex items-center justify-center rounded-2xl bg-card p-16 text-muted-foreground shadow-sm">
          <Loader2 className="h-5 w-5 animate-spin" />
        </div>
      ) : isError ? (
        <div className="rounded-2xl bg-card p-8 text-sm text-destructive shadow-sm">
          Impossible de charger les statistiques.
        </div>
      ) : (
        <>
          <div
            className={`grid gap-4 sm:grid-cols-2 ${
              isAdmin ? 'lg:grid-cols-3 xl:grid-cols-6' : 'lg:grid-cols-4'
            }`}
          >
            {cards.map((card) => (
              <DashboardStatCard key={card.label} {...card} />
            ))}
          </div>

          {isAdmin ? (
            <div className="grid gap-4 lg:grid-cols-3">
              <div className="lg:col-span-2">
                <CallsChart data={callsByDay} />
              </div>
              <TopCommercialsCard commercials={topCommercials} />
            </div>
          ) : (
            <CallsChart data={callsByDay} />
          )}
        </>
      )}
    </div>
  )
}
