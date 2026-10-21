import { Users } from 'lucide-react'
import KpiPill, { KpiBar, formatCount } from '@/pages/shared/components/KpiPill/index.jsx'
import { STATUS_PILLS } from './status-pills.js'
import { useProspectKpis } from './useProspectKpis.js'

const fmt = formatCount

/**
 * Barre « Tous + 9 statuts affichés » (10 badges au total, §9).
 *
 * @param {string[]} statusFilters        valeurs affichées sélectionnées —
 *                                        **une seule** en sélection unique
 *                                        (panel admin, détail employé) ;
 *                                        tableau vide = aucun filtre =
 *                                        « Tous ». Cette barre n'est pas
 *                                        rendue sur Prospects (commercial) —
 *                                        page qui a sa propre barre à 4
 *                                        badges.
 * @param {Function} onStatusFilterChange reçoit la valeur cliquée (bascule
 *                                        exclusive) ou `null` pour repasser à
 *                                        « Tous » ; omis = barre non
 *                                        cliquable (figée)
 * @param {object}   counts               compteurs fournis par la page
 *                                        (`{ prospects, by_display_status }`),
 *                                        périmètre **restreint** — remplace
 *                                        ceux de `GET clients/overview`.
 *                                        `undefined` (défaut) = compteurs
 *                                        globaux ; `null` = chargement en
 *                                        cours → squelette.
 * @param {string}   [source]             **déprécié** — l'origine active est
 *                                        désormais transmise via `filters.source`.
 * @param {object}   [filters]            périmètre de la liste (Grande liste
 *                                        admin) appliqué aux compteurs :
 *                                        `search`, `municipality`, `category`,
 *                                        `administrative_region`, `employee_id`,
 *                                        `source`. Chaque valeur vide/absente =
 *                                        aucun filtre ; **`enterprise_id` n'y
 *                                        figure pas** — « Entreprise » ne porte
 *                                        que la liste déroulante « Employé » et
 *                                        ne filtre pas les clients. Les compteurs
 *                                        suivent ainsi le **même** périmètre que
 *                                        les lignes rendues (invariant §9).
 */
export default function ProspectKpis({ statusFilters = [], onStatusFilterChange, counts, source, filters }) {
  const { kpis, isLoading, isError } = useProspectKpis(counts, { source, filters })

  if (isError) return null

  if (isLoading || !kpis) {
    return (
      <div className="flex flex-wrap gap-1.5" aria-hidden="true">
        {Array.from({ length: 10 }).map((_, i) => (
          <div key={i} className="h-7 w-32 animate-pulse rounded-full border border-border/60 bg-card" />
        ))}
      </div>
    )
  }

  const { prospects = {}, by_display_status: byDisplayStatus = {} } = kpis

  // Les badges ne filtrent que là où la page fournit la bascule (panel
  // admin, détail d'un employé). Ailleurs (Prospects commercial, listes de
  // rappels) les badges s'affichent avec `locked` (curseur interdit) et une
  // infobulle qui annonce l'impossibilité de changer la sélection.
  const filterable = Boolean(onStatusFilterChange)

  // NB : aucun `key` dans ces objets — il passerait dans les props étalées
  // (`<KpiPill {...pill} />`) et React le signalerait : la clé de liste est
  // posée directement sur le JSX, en destructurant `key` du badge.
  const tousPill = {
    label: 'Tous',
    primary: prospects.system ?? 0,
    value: fmt(prospects.system ?? 0),
    icon: Users,
    // « Tous » est **violet** comme sa tuile homologue du tableau de bord
    // (`DashboardStatusCards`) : le premier badge de la barre reste ainsi
    // distinct du « Libre » bleu qui le suit.
    iconClass: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
    activeClass: 'border border-transparent bg-violet-600',
    activeFg: 'text-white',
    compact: true,
    active: statusFilters.length === 0,
    onClick: filterable ? () => onStatusFilterChange(null) : undefined,
    locked: !filterable,
    title: filterable
      ? 'Tous les clients du périmètre — clique pour repasser à « Tous »'
      : 'Liste non filtrable par statut ici — chiffres globaux',
  }

  const statusPills = STATUS_PILLS.map((pill) => {
    const count = byDisplayStatus[pill.key] ?? 0
    const active = statusFilters.includes(pill.key)

    let title = pill.title
    if (!filterable) {
      title = active
        ? `Filtre figé sur « ${pill.label} » — non modifiable ici`
        : `${pill.label} : ${count} client(s) — chiffres globaux, non modifiable ici`
    }

    return {
      ...pill,
      primary: count,
      value: fmt(count),
      compact: true,
      active,
      onClick: filterable ? () => onStatusFilterChange(pill.key) : undefined,
      locked: !filterable,
      title,
    }
  })

  return (
    <KpiBar dense>
      <KpiPill {...tousPill} />
      {statusPills.map(({ key: badgeKey, ...pillProps }) => (
        <KpiPill key={badgeKey} {...pillProps} />
      ))}
    </KpiBar>
  )
}
