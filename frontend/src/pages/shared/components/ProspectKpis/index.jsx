import { Ban, CircleCheck, PhoneCall, ThumbsDown, ThumbsUp, Users, Voicemail } from 'lucide-react'
import KpiPill, { KpiBar, formatCount } from '@/pages/shared/components/KpiPill/index.jsx'
import { useProspectKpis } from './useProspectKpis.js'

const fmt = formatCount

/**
 * Barre de filtres de la colonne « Statut » (docs/RULES.md §9) — **c'est LE
 * filtre de statut** : le menu déroulant « Statut » a été supprimé.
 *
 * **Ordre imposé, identique sur les listes** (Grande liste — panel
 * admin, À rappeler, BV, détail d'un employé) :
 * `Tous` → `Disponible` → `Oui` → `Non` → `BV` → `À rappeler` → `Blacklist`.
 *
 * Chaque badge compte une **valeur affichée** (`by_display_status` de
 * `GET clients/overview`), produite par le scope qui pilote le filtre :
 * le chiffre affiché vaut donc le nombre de lignes rendues après clic.
 *
 *  - **panel admin** (Grande liste) : sélection **unique** (un clic rend le
 *    badge seul actif, un second clic dessus repasse à *Tous*,
 *    `aria-pressed`) ; *Tous* retire l'unique filtre actif. Disponible /
 *    Blacklist filtrent le **statut client**, les autres le **statut de la
 *    réservation courante** — deux paramètres serveur distincts, union `OR`
 *    côté requête ;
 *  - **détail d'un employé** (onglet Historique) : mêmes badges en sélection
 *    unique, mais compteurs **propres à l'employé** (props `counts`) ;
 *  - **panel commercial** (Prospects) : **barre retirée** à la demande
 *    (plus aucun badge de statut sur la « Grande liste » commerciale) ;
 *  - **listes de rappels** : barre consultative, aucun badge cliquable.
 *
 * Couleur pleine à la sélection, sans bordure : chaque badge garde **sa**
 * couleur de fond, texte et icône passés en contraste (`activeFg`) — une
 * teinte distincte par badge pour rester lisible à7 pastilles.
 * Tous les compteurs sont affichés, même à 0.
 */
const STATUS_PILLS = [
  {
    key: 'AVAILABLE',
    label: 'Disponible',
    icon: CircleCheck,
    iconClass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    activeClass: 'border border-transparent bg-emerald-700',
    activeFg: 'text-white',
    title: 'Clients (re)disponibles — clique pour filtrer',
  },
  {
    key: 'YES',
    label: 'Oui',
    icon: ThumbsUp,
    iconClass: 'bg-teal-500/10 text-teal-600 dark:text-teal-400',
    activeClass: 'border border-transparent bg-teal-600',
    activeFg: 'text-white',
    title: 'Réservation courante « Oui » — clique pour filtrer',
  },
  {
    key: 'NO',
    label: 'Non',
    icon: ThumbsDown,
    iconClass: 'bg-destructive/10 text-destructive',
    activeClass: 'border border-transparent bg-destructive',
    activeFg: 'text-white',
    title: 'Réservation courante « Non » — clique pour filtrer',
  },
  {
    key: 'BV_VOICEMAIL',
    label: 'BV',
    icon: Voicemail,
    iconClass: 'bg-amber-500/10 text-[var(--warning-fg)]',
    activeClass: 'border border-transparent bg-amber-600',
    activeFg: 'text-white',
    title: 'Réservation courante « Boîte vocale » — clique pour filtrer',
  },
  {
    key: 'CALL_BACK',
    label: 'À rappeler',
    icon: PhoneCall,
    iconClass: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
    activeClass: 'border border-transparent bg-indigo-600',
    activeFg: 'text-white',
    title: 'Réservation courante « À rappeler » — clique pour filtrer',
  },
  {
    key: 'BLACKLISTED',
    label: 'Blacklist',
    icon: Ban,
    iconClass: 'bg-[var(--status-badge)] text-[var(--status-badge-foreground)]',
    activeClass: 'border border-transparent bg-[var(--status-badge)]',
    activeFg: 'text-[var(--status-badge-foreground)]',
    title: 'Clients en liste noire (badge noir en clair, gris en sombre) — clique pour filtrer',
  },
]

/** Badges servis par le paramètre `status` (les autres : `reservation_status`). */
export const CLIENT_STATUS_KEYS = ['AVAILABLE', 'BLACKLISTED']

/**
 * Barre « Tous + 6 statuts affichés ».
 *
 * @param {string[]} statusFilters        valeurs affichées sélectionnées —
 *                                        **une seule** en sélection unique
 *                                        (panel admin, détail employé) ;
 *                                        tableau vide = aucun filtre =
 *                                        « Tous ». La barre n'est plus du
 *                                        tout rendue sur Prospects
 *                                        (commercial).
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
 */
export default function ProspectKpis({ statusFilters = [], onStatusFilterChange, counts }) {
  const { kpis, isLoading, isError } = useProspectKpis(counts)

  if (isError) return null

  if (isLoading || !kpis) {
    return (
      <div className="flex flex-wrap gap-2" aria-hidden="true">
        {Array.from({ length: 7 }).map((_, i) => (
          <div key={i} className="h-8 w-40 animate-pulse rounded-full border border-border/60 bg-card" />
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
    iconClass: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    activeClass: 'border border-transparent bg-blue-600',
    activeFg: 'text-white',
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
      active,
      onClick: filterable ? () => onStatusFilterChange(pill.key) : undefined,
      locked: !filterable,
      title,
    }
  })

  return (
    <KpiBar>
      <KpiPill {...tousPill} />
      {statusPills.map(({ key: badgeKey, ...pillProps }) => (
        <KpiPill key={badgeKey} {...pillProps} />
      ))}
    </KpiBar>
  )
}
