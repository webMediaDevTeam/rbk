import {
  Bar,
  CartesianGrid,
  ComposedChart,
  Line,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'

// Date complète au survol : 'YYYY-MM-DD' → 'JJ/MM/AAAA', 'YYYY-MM' → 'MM/AAAA'.
const formatTooltipDate = (date) => (date ? date.split('-').reverse().join('/') : '')

// Taux journalier (%) : null quand la période n'a aucun appel (le point n'est
// pas tracé plutôt que d'afficher un faux 0 %).
const withRate = (data) =>
  data.map((point) => ({
    ...point,
    rate: point.total > 0 ? Math.round((point.oui / point.total) * 1000) / 10 : null,
  }))

function ChartTooltip({ active, payload }) {
  if (!active || !payload?.length) return null

  return (
    <div className="rounded-lg bg-popover px-3 py-2 text-xs shadow-md">
      <p className="mb-1 font-medium text-popover-foreground">
        {formatTooltipDate(payload[0]?.payload?.date)}
      </p>
      {payload.map((entry) => (
        <p key={entry.dataKey} className="flex items-center justify-between gap-4 text-muted-foreground">
          <span>{entry.name}</span>
          <span className="font-semibold text-popover-foreground tabular-nums">
            {entry.dataKey === 'rate' ? (entry.value == null ? '—' : `${entry.value} %`) : entry.value}
          </span>
        </p>
      ))}
    </div>
  )
}

/**
 * « Évolution des appels ». Composant purement présentationnel : le filtre
 * de période global (`DashboardFilterBar`) pilote les données — un point par
 * jour (mois ou semaine choisis) ou par mois (année seule), transmis déjà
 * calculés. Axe gauche : taux d'appels validés en % (courbe) ; axe droit :
 * volume d'appels (barres). `caption` décrit la période rendue.
 */
export default function CallsChart({ data = [], caption = '' }) {
  const points = withRate(data)

  // Taux moyen de la période, pondéré par le volume d'appels.
  const totalCalls = data.reduce((sum, point) => sum + (point.total ?? 0), 0)
  const totalOui = data.reduce((sum, point) => sum + (point.oui ?? 0), 0)
  const averageRate = totalCalls > 0 ? ((totalOui / totalCalls) * 100).toFixed(1) : null

  return (
    <div className="rounded-2xl bg-card p-6 text-card-foreground shadow-sm">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 className="text-base font-semibold">Évolution des appels</h3>
          <p className="text-sm text-muted-foreground">
            {caption}
            {averageRate !== null ? ` — taux moyen ${averageRate} %` : ''}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-4">
          <div className="hidden items-center gap-4 text-xs text-muted-foreground md:flex">
            <span className="flex items-center gap-1.5">
              <span className="h-2.5 w-2.5 rounded-full bg-primary" /> Appels
            </span>
            <span className="flex items-center gap-1.5">
              <span className="h-2.5 w-2.5 rounded-full bg-emerald-500" /> OUI
            </span>
            <span className="flex items-center gap-1.5">
              <span className="h-0.5 w-4 rounded-full bg-amber-500" /> Taux OUI (%)
            </span>
          </div>
        </div>
      </div>
      <div className="mt-6 h-[300px] w-full">
        <ResponsiveContainer width="100%" height="100%">
          <ComposedChart data={points} barGap={4}>
            <CartesianGrid vertical={false} strokeDasharray="3 3" className="stroke-border" />
            <XAxis dataKey="label" stroke="#888888" fontSize={12} tickLine={false} axisLine={false} />
            {/* Axe gauche : le taux d'appels validés, en pourcentage. */}
            <YAxis
              yAxisId="rate"
              domain={[0, 'auto']}
              stroke="#888888"
              fontSize={12}
              tickLine={false}
              axisLine={false}
              allowDecimals={false}
              width={40}
              tickFormatter={(value) => `${value}%`}
            />
            {/* Axe droit : les volumes (barres). */}
            <YAxis
              yAxisId="count"
              orientation="right"
              stroke="#888888"
              fontSize={12}
              tickLine={false}
              axisLine={false}
              allowDecimals={false}
              width={32}
            />
            <Tooltip cursor={{ className: 'fill-muted' }} content={<ChartTooltip />} />
            <Bar
              yAxisId="count"
              dataKey="total"
              name="Appels"
              fill="currentColor"
              radius={[4, 4, 0, 0]}
              className="fill-primary"
            />
            <Bar
              yAxisId="count"
              dataKey="oui"
              name="OUI"
              fill="currentColor"
              radius={[4, 4, 0, 0]}
              className="fill-emerald-500"
            />
            <Line
              yAxisId="rate"
              dataKey="rate"
              name="Taux OUI (%)"
              type="monotone"
              stroke="currentColor"
              className="stroke-amber-500"
              strokeWidth={2}
              dot={{ r: 3 }}
              activeDot={{ r: 4 }}
            />
          </ComposedChart>
        </ResponsiveContainer>
      </div>
    </div>
  )
}
