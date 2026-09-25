const numberFormat = new Intl.NumberFormat('fr-FR')

/** Compteur formaté « 1 234 » (fr-FR), tolère undefined/null. */
export const formatCount = (n) => numberFormat.format(n ?? 0)

/** Barre horizontale qui contient les badges (une ligne, passe à la ligne si étroit). */
export function KpiBar({ children }) {
  return <div className="flex flex-wrap items-center gap-2">{children}</div>
}

/**
 * Badge KPI compact (une ligne) : pastille couleur + libellé + valeur +
 * suffixe. Partagé par la barre KPI des prospects et par les pages
 * « Mes listes » (total des listes, traités / non traités d'une liste).
 */
export default function KpiPill({ label, value, suffix, suffixClass = '', icon: Icon, iconClass, title }) {
  return (
    <span
      title={title}
      className="inline-flex items-center gap-2 rounded-full border border-border/60 bg-card py-1.5 pl-2 pr-3.5 text-sm shadow-sm transition-colors hover:border-primary/40"
    >
      <span className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full ${iconClass}`}>
        <Icon className="h-3.5 w-3.5" />
      </span>
      <span className="whitespace-nowrap text-muted-foreground">{label}</span>
      <span className="whitespace-nowrap font-semibold tabular-nums text-foreground">{value}</span>
      {suffix ? (
        <span className={`whitespace-nowrap text-xs tabular-nums ${suffixClass}`}>{suffix}</span>
      ) : null}
    </span>
  )
}
