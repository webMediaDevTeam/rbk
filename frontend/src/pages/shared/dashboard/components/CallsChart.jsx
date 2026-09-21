import {
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'

function ChartTooltip({ active, payload, label }) {
  if (!active || !payload?.length) return null

  return (
    <div className="rounded-lg bg-popover px-3 py-2 text-xs shadow-md">
      <p className="mb-1 font-medium text-popover-foreground">{label}</p>
      {payload.map((entry) => (
        <p key={entry.dataKey} className="flex items-center justify-between gap-4 text-muted-foreground">
          <span>{entry.name}</span>
          <span className="font-semibold text-popover-foreground tabular-nums">{entry.value}</span>
        </p>
      ))}
    </div>
  )
}

export default function CallsChart({ data = [] }) {
  return (
    <div className="rounded-2xl bg-card p-6 text-card-foreground shadow-sm">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 className="text-base font-semibold">Appels des 14 derniers jours</h3>
          <p className="text-sm text-muted-foreground">Total des appels et réussites (OUI)</p>
        </div>
        <div className="flex items-center gap-4 text-xs text-muted-foreground">
          <span className="flex items-center gap-1.5">
            <span className="h-2.5 w-2.5 rounded-full bg-primary" /> Appels
          </span>
          <span className="flex items-center gap-1.5">
            <span className="h-2.5 w-2.5 rounded-full bg-emerald-500" /> OUI
          </span>
        </div>
      </div>
      <div className="mt-6 h-[300px] w-full">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={data} barGap={4}>
            <CartesianGrid vertical={false} strokeDasharray="3 3" className="stroke-border" />
            <XAxis dataKey="label" stroke="#888888" fontSize={12} tickLine={false} axisLine={false} />
            <YAxis
              stroke="#888888"
              fontSize={12}
              tickLine={false}
              axisLine={false}
              allowDecimals={false}
              width={28}
            />
            <Tooltip cursor={{ className: 'fill-muted' }} content={<ChartTooltip />} />
            <Bar dataKey="total" name="Appels" fill="currentColor" radius={[4, 4, 0, 0]} className="fill-primary" />
            <Bar dataKey="oui" name="OUI" fill="currentColor" radius={[4, 4, 0, 0]} className="fill-emerald-500" />
          </BarChart>
        </ResponsiveContainer>
      </div>
    </div>
  )
}
