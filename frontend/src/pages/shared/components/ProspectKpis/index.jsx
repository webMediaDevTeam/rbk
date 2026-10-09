import { Ban, CircleCheck, Copy, Info, PhoneCall, PhoneOff, ThumbsDown, ThumbsUp, Users, Voicemail } from 'lucide-react'
import KpiPill, { KpiBar, formatCount } from '@/pages/shared/components/KpiPill/index.jsx'
import { useProspectKpis } from './useProspectKpis.js'

const fmt = formatCount

/**
 * Barre de filtres de la colonne « Statut » (docs/RULES.md §9) — **c'est LE
 * filtre de statut** : le menu déroulant « Statut » a été supprimé.
 *
 * **Ordre imposé, identique sur les listes** (Grande liste — panel
 * admin, À rappeler, BV, détail d'un employé) :
 * `Tous` → `Libre` → `Oui` → `Non` → `BV` → `À rapp..` → `Double`
 * → `Info` → `Blacklist` → `Sans tel..` (libellés courts, cf. §9).
 *
 * Chaque badge compte une **valeur affichée** (`by_display_status` de
 * `GET clients/overview`), produite par le scope qui pilote le filtre :
 * le chiffre affiché vaut donc le nombre de lignes rendues après clic.
 *
 *  - **panel admin** (Grande liste) : sélection **unique** (un clic rend le
 *    badge seul actif, un second clic dessus repasse à *Tous*,
 *    `aria-pressed`) ; *Tous* retire l'unique filtre actif. Libre /
 *    Blacklist filtrent le **statut client**, les autres (dont `Double` /
 *    `Info`) le **statut de la
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
 * teinte distincte par badge pour rester lisible à 10 pastilles (*Double*
 * est en **couleur primaire** de l'app). Pastilles rendues en `compact`
 * (`KpiPill`) pour tenir sur **une seule ligne**. Tous les compteurs sont
 * affichés, même à 0.
 */
const STATUS_PILLS = [
  {
    key: 'AVAILABLE',
    label: 'Libre',
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
    label: 'À rapp..',
    icon: PhoneCall,
    iconClass: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
    activeClass: 'border border-transparent bg-indigo-600',
    activeFg: 'text-white',
    title: 'Réservation courante « À rappeler » — clique pour filtrer',
  },
  {
    // Issues « Double » / « Info » : prospect toujours **tenu** par
    // l'employé (régime « Réservé ») — filtre sur la réservation courante,
    // comme les autres issues. « Double » affiche la **couleur primaire**
    // de l'app (`#a21caf`), à la demande.
    key: 'DOUBLE',
    label: 'Double',
    icon: Copy,
    iconClass: 'bg-primary/10 text-primary',
    activeClass: 'border border-transparent bg-primary',
    activeFg: 'text-white',
    title: 'Réservation courante « Double » — clique pour filtrer',
  },
  {
    key: 'INFO',
    label: 'Info',
    icon: Info,
    iconClass: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
    activeClass: 'border border-transparent bg-sky-600',
    activeFg: 'text-white',
    title: 'Réservation courante « Info » — clique pour filtrer',
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
  {
    // Seau **dérivé** (`phone` vide) : recoupe les autres (un prospect sans
    // numéro est aussi `AVAILABLE` ou blacklisté) — sélection unique dans
    // l'UI, donc le compteur = lignes rendues après clic reste exact.
    key: 'SANS_TELEPHONE',
    label: 'Sans tel..',
    icon: PhoneOff,
    iconClass: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
    activeClass: 'border border-transparent bg-rose-600',
    activeFg: 'text-white',
    title: 'Prospects sans numéro de téléphone (statut dérivé) — clique pour filtrer',
  },
]

/** Badges servis par le paramètre `status` (les autres : `reservation_status`). */
export const CLIENT_STATUS_KEYS = ['AVAILABLE', 'BLACKLISTED', 'SANS_TELEPHONE']

/**
 * Barre « Tous + 9 statuts affichés » (10 badges au total, §9).
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
 * @param {string}   [source]             origine active (onglet de la Grande
 *                                        liste admin) : bornée côté serveur
 *                                        (`GET clients/overview?source=`),
 *                                        aucun effet ailleurs (paramètre omis
 *                                        = périmètre entier).
 */
export default function ProspectKpis({ statusFilters = [], onStatusFilterChange, counts, source }) {
  const { kpis, isLoading, isError } = useProspectKpis(counts, { source })

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
    iconClass: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    activeClass: 'border border-transparent bg-blue-600',
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
