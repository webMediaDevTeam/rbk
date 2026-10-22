import { Loader2 } from 'lucide-react'
import { useDashboard } from './useDashboard.js'
import DashboardFilterBar from './components/DashboardFilterBar.jsx'
import DashboardStatTiles from './components/DashboardStatTiles.jsx'
import DashboardStatusCards from './components/DashboardStatusCards.jsx'
import CallsChart from './components/CallsChart.jsx'
import TopCommercialsCard from './components/TopCommercialsCard.jsx'

export default function DashboardPage() {
  const {
    isLoading,
    isError,
    isAdmin,
    isOperator,
    cards,
    panel,
    source,
    setSource,
    callsByDay,
    topCommercials,
    caption,
    years,
    year,
    month,
    week,
    day,
    setYear,
    setMonth,
    setWeek,
    setDay,
    range,
    preset,
    applyPreset,
    resetPeriod,
    enterprise,
    employee,
    setEnterprise,
    setEmployee,
  } = useDashboard()

  const chart = <CallsChart data={callsByDay} caption={caption} />

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Dashboard</h1>
        <p className="text-sm text-muted-foreground">
          {isAdmin
            ? 'Vue globale : chiffres filtrés par la période sélectionnée'
            : isOperator
              ? 'Vos saisies de numéro de téléphone et ce qui reste à faire'
              : 'Vos statistiques personnelles, filtrées par la période sélectionnée'}
        </p>
      </div>

      {/* Filtre de période global : cartes, graphique, réservations et
          « Meilleurs employés » suivent Année → Mois → Semaine → Jour. Le
          gestionnaire ajoute l'onglet « Source » à droite — le **même** que celui
          de la Grande liste, partagé et mémorisé (`useSourcePreference`).

          Masqué pour l'`OPERATOR` : ses tuiles sont des instantanés et il n'a
          pas de graphique — un filtre sans effet serait trompeur. */}
      {!isOperator && (
        <DashboardFilterBar
          years={years}
          year={year}
          month={month}
          week={week}
          day={day}
          range={range}
          preset={preset}
          onYearChange={setYear}
          onMonthChange={setMonth}
          onWeekChange={setWeek}
          onDayChange={setDay}
          onPresetChange={applyPreset}
          onReset={resetPeriod}
          showSource={isAdmin}
          source={source}
          onSourceChange={setSource}
          showEnterprise={isAdmin}
          enterprise={enterprise}
          employee={employee}
          onEnterpriseChange={setEnterprise}
          onEmployeeChange={setEmployee}
        />
      )}

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
          {/* Gestionnaire : les **compteurs de la Grande liste** — les 10 badges
              de statut en tuiles pastel, chacun avec sa part (pourcentage +
              jauge) et menant à la liste pré-filtrée. Bornés par la période
              sélectionnée (clients actifs de la fenêtre) **et** par l'origine :
              changer un filtre les recalcule aussitôt. */}
          {isAdmin && (
            <DashboardStatusCards
              source={source}
              year={year}
              month={month}
              week={week}
              day={day}
              range={range}
              employee={employee}
            />
          )}

          {/* Commercial : le travail en cours (Libre / Double / Info / BV /
              À rappeler) — même maquette, mêmes teintes. Opérateur : ses
              saisies de téléphone. Le gestionnaire n'a pas de second panneau
              (les tuiles de période ont été retirées : elles doublaient le
              graphique et la Grande liste). */}
          {panel ? (
            <DashboardStatTiles
              title={panel.title}
              caption={panel.caption}
              tiles={cards}
              meter={panel.meter}
            />
          ) : null}

          {/* Graphique et « Meilleurs employés » : l'opérateur n'appelle pas et
              ne gère personne — ses tuiles suffisent. */}
          {isOperator ? null : isAdmin ? (
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
