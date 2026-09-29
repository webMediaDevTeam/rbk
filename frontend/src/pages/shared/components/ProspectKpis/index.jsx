import { Ban, CalendarCheck, CircleCheck, Hourglass, Users } from 'lucide-react'
import KpiPill, { KpiBar, formatCount } from '@/pages/shared/components/KpiPill/index.jsx'
import { useProspectKpis } from './useProspectKpis.js'

const fmt = formatCount

/**
 * Barre de filtres par statut client (compteurs `by_status` de
 * `GET clients/overview`) — **c'est LE filtre de statut** : le menu déroulant
 * « Statut » a été supprimé, de même que les anciens badges KPI (« Prospects »,
 * « Réservés traités », « Succès / traités », « En cours / traités »).
 *
 * **Ordre imposé, identique sur les deux pages** :
 * `Tous` → `Disponible` → `Réservé` → `Non disponible` → `Blacklisté`.
 *
 *  - **panel admin** : sélection multiple (un clic ajoute, un second retire,
 *    `aria-pressed`) ; `Tous` retire toutes les sélections ;
 *  - **panel commercial** : le filtre est **figé sur Disponible** — `Disponible`
 *    s'affiche sélectionné et **aucun badge n'est cliquable** (`locked`) ;
 *  - **couleur pleine à la sélection, sans bordure violette** : chaque badge
 *    garde **sa** couleur de fond, texte et icône passés en contraste
 *    (`activeFg`) ;
 *  - **tous les compteurs sont affichés, même à 0** : aucun statut ne disparaît.
 */
const STATUS_PILLS = [
  {
    key: 'AVAILABLE',
    label: 'Disponible',
    icon: CircleCheck,
    iconClass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    activeClass: 'border border-transparent bg-emerald-700',
    activeFg: 'text-white',
    title: 'Clients au statut Disponible — clique pour filtrer',
  },
  {
    key: 'RESERVED',
    label: 'Réservé',
    icon: CalendarCheck,
    iconClass: 'bg-amber-500/10 text-[var(--warning-fg)]',
    activeClass: 'border border-transparent bg-amber-700',
    activeFg: 'text-white',
    title: 'Clients au statut Réservé — clique pour filtrer',
  },
  {
    key: 'UNAVAILABLE',
    label: 'Non disponible',
    icon: Hourglass,
    iconClass: 'bg-destructive/10 text-destructive',
    activeClass: 'border border-transparent bg-destructive',
    activeFg: 'text-white',
    title: 'Clients au statut Non disponible — clique pour filtrer',
  },
  {
    key: 'BLACKLISTED',
    label: 'Blacklisté',
    icon: Ban,
    iconClass: 'bg-[var(--status-badge)] text-[var(--status-badge-foreground)]',
    activeClass: 'border border-transparent bg-[var(--status-badge)]',
    activeFg: 'text-[var(--status-badge-foreground)]',
    title: 'Clients en liste noire (badge noir en clair, gris en sombre) — clique pour filtrer',
  },
]

/**
 * Barre « Tous + 4 statuts ».
 *
 * @param {string[]} statusFilters        statuts sélectionnés (panel admin) ;
 *                                        tableau vide = aucun filtre = « Tous ».
 *                                        Sur Prospects (commercial), figé sur
 *                                        `['AVAILABLE']`.
 * @param {Function} onStatusFilterChange reçoit le statut cliqué (bascule) ou
 *                                        `null` pour tout retirer (« Tous ») ;
 *                                        omis = barre non cliquable (figée)
 */
export default function ProspectKpis({ statusFilters = [], onStatusFilterChange }) {
  const { kpis, isLoading, isError } = useProspectKpis()

  if (isError) return null

  if (isLoading || !kpis) {
    return (
      <div className="flex flex-wrap gap-2" aria-hidden="true">
        {Array.from({ length: 5 }).map((_, i) => (
          <div key={i} className="h-8 w-40 animate-pulse rounded-full border border-border/60 bg-card" />
        ))}
      </div>
    )
  }

  const { prospects, by_status: byStatus = {} } = kpis

  // Les badges ne filtrent que là où la page fournit la bascule (panel
  // admin). Ailleurs (Prospects commercial) le filtre est figé sur
  // « Disponible » : les badges s'affichent avec `locked` (curseur interdit)
  // et une infobulle qui annonce l'impossibilité de changer la sélection.
  const filterable = Boolean(onStatusFilterChange)

  const tousPill = {
    key: 'ALL',
    label: 'Tous',
    primary: prospects.system,
    value: fmt(prospects.system),
    icon: Users,
    iconClass: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    activeClass: 'border border-transparent bg-blue-600',
    activeFg: 'text-white',
    active: statusFilters.length === 0,
    onClick: filterable ? () => onStatusFilterChange(null) : undefined,
    locked: !filterable,
    title: filterable
      ? 'Tous les clients — clique pour retirer tous les filtres de statut'
      : 'Filtre figé sur « Disponible » : cette liste ne peut afficher que des prospects disponibles',
  }

  const statusPills = STATUS_PILLS.map((pill) => {
    const count = byStatus[pill.key] ?? 0
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
      active,
      onClick: filterable ? () => onStatusFilterChange(pill.key) : undefined,
      locked: !filterable,
      title,
    }
  })

  return (
    <KpiBar>
      <KpiPill {...tousPill} />
      {statusPills.map((pill) => (
        <KpiPill key={pill.key} {...pill} />
      ))}
    </KpiBar>
  )
}
