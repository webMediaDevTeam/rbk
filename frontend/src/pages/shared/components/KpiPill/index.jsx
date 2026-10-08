const numberFormat = new Intl.NumberFormat('fr-FR')

/** Compteur formaté « 1 234 » (fr-FR), tolère undefined/null. */
export const formatCount = (n) => numberFormat.format(n ?? 0)

/** Barre horizontale qui contient les badges (une ligne, passe à la ligne si étroit). */
export function KpiBar({ children, dense = false }) {
  return (
    <div className={`flex flex-wrap items-center ${dense ? 'gap-1.5' : 'gap-2'}`}>{children}</div>
  )
}

/**
 * Badge KPI compact (une ligne) : pastille couleur + libellé + valeur +
 * suffixe. Partagé par la barre KPI des prospects et par les pages
 * « Mes listes » (total des listes, traités / non traités d'une liste).
 *
 * Passe un `<button>` (filtrant) dès qu'un `onClick` est fourni. Le badge
 * **sélectionné** est en **couleur pleine** (aucune bordure : `activeClass`
 * porte la couleur de fond), texte/icône passés en `activeFg` sur un halo
 * `activeIconClass`. `locked` marque un badge non cliquable dont l'état ne
 * peut pas être changé (curseur interdit). Sans `onClick`, le badge reste
 * informatif. `compact` réduit la maquette (padding, texte `xs`, pastille
 * d'icône 20 px) pour les barres chargées à 10 badges.
 */
export default function KpiPill({
  label,
  value,
  suffix,
  suffixClass = '',
  icon: Icon,
  iconClass,
  title,
  onClick,
  active = false,
  activeClass = 'border border-transparent bg-primary',
  activeFg = 'text-white',
  activeIconClass = 'bg-white/20',
  locked = false,
  compact = false,
}) {
  const className = [
    // `compact` : barres à 10 badges (Grande liste) — même maquette, tout
    // réduit d'un cran pour que les pastilles **tiennent sur une ligne**.
    compact
      ? 'inline-flex items-center gap-1.5 rounded-full border py-1 pl-1.5 pr-2.5 text-xs shadow-sm transition-colors'
      : 'inline-flex items-center gap-2 rounded-full border py-1.5 pl-2 pr-3.5 text-sm shadow-sm transition-colors',
    active ? activeClass : 'border-border/60 bg-card hover:border-primary/40',
    onClick && !active ? 'cursor-pointer hover:bg-muted' : '',
    // Badge non cliquable (barre consultative : listes de rappels, sélection
    // figée) : on signale visuellement que la sélection ne peut pas changer.
    locked && !onClick ? 'cursor-not-allowed select-none' : '',
  ].filter(Boolean).join(' ')

  const content = (
    <>
      <span
        className={`flex shrink-0 items-center justify-center rounded-full ${compact ? 'h-5 w-5' : 'h-6 w-6'} ${active ? `${activeIconClass} ${activeFg}` : iconClass}`}
      >
        <Icon className={compact ? 'h-3 w-3' : 'h-3.5 w-3.5'} />
      </span>
      <span className={`whitespace-nowrap ${active ? activeFg : 'text-muted-foreground'}`}>{label}</span>
      <span className={`whitespace-nowrap font-semibold tabular-nums ${active ? activeFg : 'text-foreground'}`}>{value}</span>
      {suffix ? (
        <span className={`whitespace-nowrap text-xs tabular-nums ${active ? activeFg : suffixClass}`}>{suffix}</span>
      ) : null}
    </>
  )

  if (onClick) {
    return (
      <button type="button" onClick={onClick} title={title} aria-pressed={active} className={className}>
        {content}
      </button>
    )
  }

  return (
    <span title={title} className={className}>
      {content}
    </span>
  )
}
