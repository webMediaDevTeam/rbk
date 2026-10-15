import { Loader2 } from 'lucide-react'
import { useDashboard } from './useDashboard.js'
import DashboardStatCard from './components/DashboardStatCard.jsx'
import CallsChart from './components/CallsChart.jsx'
import TopCommercialsCard from './components/TopCommercialsCard.jsx'

export default function DashboardPage() {
  const {
    isLoading,
    isError,
    isAdmin,
    cards,
    callsByDay,
    topCommercials,
    period,
    setPeriod,
    year,
    setYear,
    month,
    setMonth,
    availableYears,
  } = useDashboard()

  const chart = (
    <CallsChart
      data={callsByDay}
      period={period}
      onPeriodChange={setPeriod}
      year={year}
      month={month}
      availableYears={availableYears}
      onYearChange={setYear}
      onMonthChange={setMonth}
    />
  )

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Dashboard</h1>
        <p className="text-sm text-muted-foreground">
          {isAdmin
            ? 'Vue globale : appels de la journée et chiffres de la base'
            : 'Vos appels de la journée et statistiques personnelles'}
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
          {/* 4 cartes d'appel du jour côté gestionnaire, 5 côté commercial —
              une rangée de 4 en écran large. */}
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {cards.map((card) => (
              <DashboardStatCard key={card.label} {...card} />
            ))}
          </div>

          {isAdmin ? (
            <div className="grid gap-4 lg:grid-cols-3">
              <div className="lg:col-span-2">{chart}</div>
              <TopCommercialsCard commercials={topCommercials} />
            </div>
          ) : (
            chart
          )}
        </>
      )}
    </div>
  )
}
