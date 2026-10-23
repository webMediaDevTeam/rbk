import { Ban, CircleCheck, Copy, CopyCheck, Info, PhoneCall, PhoneOff, Tag, ThumbsDown, ThumbsUp, Voicemail } from 'lucide-react'

/**
 * Métadonnées des **10 badges de la colonne « Statut »** (docs/RULES.md §9) :
 * libellés courts, clés serveur et teintes. Sorties du composant pour que
 * **deux écrans partagent la même définition** :
 *
 *  - la barre de filtres de la Grande liste (`ProspectKpis`) ;
 *  - la rangée de cartes « mêmes compteurs que la liste » du tableau de bord
 *    gestionnaire (`DashboardStatusCards`).
 *
 * Les clés sont les **valeurs affichées** rendues par `by_display_status` de
 * `GET clients/overview` — donc les valeurs acceptées par le filtre `status` /
 * `reservation_status` de la liste : le chiffre d'un badge égale le nombre de
 * lignes rendues après clic (§9).
 *
 * **Ordre imposé, identique sur les listes** :
 * `Libre` → `Oui` → `Non` → `BV` → `À rapp..` → `Double` → `Info`
 * → `Blacklist` → `Sans tel..` (le badge « Tous » est ajouté par l'appelant).
 *
 * Chaque entrée porte aussi un `tone` : la **teinte sémantique** utilisée par
 * les tuiles du tableau de bord (`StatTile`) — même couleur du « Oui » vert au
 * « Double » primaire, de la pastille de liste à la carte du dashboard.
 */
export const STATUS_PILLS = [
  {
    key: 'AVAILABLE',
    tone: 'blue',
    label: 'Libre',
    icon: CircleCheck,
    iconClass: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    activeClass: 'border border-transparent bg-blue-600',
    activeFg: 'text-white',
    title: 'Clients (re)disponibles — clique pour filtrer',
  },
  {
    key: 'YES',
    tone: 'emerald',
    label: 'Oui',
    icon: ThumbsUp,
    iconClass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    activeClass: 'border border-transparent bg-emerald-700',
    activeFg: 'text-white',
    title: 'Réservation courante « Oui » — clique pour filtrer',
  },
  {
    key: 'NO',
    tone: 'rose',
    label: 'Non',
    icon: ThumbsDown,
    iconClass: 'bg-destructive/10 text-destructive',
    activeClass: 'border border-transparent bg-destructive',
    activeFg: 'text-white',
    title: 'Réservation courante « Non » — clique pour filtrer',
  },
  {
    key: 'BV_VOICEMAIL',
    tone: 'amber',
    label: 'BV',
    icon: Voicemail,
    iconClass: 'bg-amber-500/10 text-[var(--warning-fg)]',
    activeClass: 'border border-transparent bg-amber-600',
    activeFg: 'text-white',
    title: 'Réservation courante « Boîte vocale » — clique pour filtrer',
  },
  {
    key: 'CALL_BACK',
    tone: 'indigo',
    label: 'À rapp..',
    // Libellé long des tuiles du tableau de bord (pas de pastille à tenir sur une ligne).
    tileLabel: 'À rappeler',
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
    tone: 'brand',
    label: 'Double',
    icon: Copy,
    iconClass: 'bg-primary/10 text-primary',
    activeClass: 'border border-transparent bg-primary',
    activeFg: 'text-white',
    title: 'Réservation courante « Double » — clique pour filtrer',
  },
  {
    key: 'INFO',
    tone: 'cyan',
    label: 'Info',
    icon: Info,
    iconClass: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
    activeClass: 'border border-transparent bg-sky-600',
    activeFg: 'text-white',
    title: 'Réservation courante « Info » — clique pour filtrer',
  },
  {
    // Issue « Autre » : prospect toujours **tenu** par l'employé (régime
    // « Réservé »), la raison est portée par sa note libre — filtre sur la
    // réservation courante, comme Double / Info. Teinte violet distincte.
    key: 'AUTRE',
    tone: 'violet',
    label: 'Autre',
    icon: Tag,
    iconClass: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
    activeClass: 'border border-transparent bg-violet-600',
    activeFg: 'text-white',
    title: 'Réservation courante « Autre » — clique pour filtrer',
  },
  {
    key: 'BLACKLISTED',
    tone: 'slate',
    label: 'Blacklist',
    icon: Ban,
    iconClass: 'bg-[var(--status-badge)] text-[var(--status-badge-foreground)]',
    activeClass: 'border border-transparent bg-[var(--status-badge)]',
    activeFg: 'text-[var(--status-badge-foreground)]',
    title: 'Clients en liste noire (badge noir en clair, gris en sombre) — clique pour filtrer',
  },
  {
    // Statut client posé par « Marquer comme répété » (onglet « Numéros
    // répétés » de la fiche) : les fiches rattachées à un doublon d'origine
    // (`parent_client_id`). Filtre sur `status`, comme Libre / Blacklist.
    key: 'REPETE',
    tone: 'fuchsia',
    label: 'num Répétés',
    icon: CopyCheck,
    iconClass: 'bg-fuchsia-500/10 text-fuchsia-600 dark:text-fuchsia-400',
    activeClass: 'border border-transparent bg-fuchsia-600',
    activeFg: 'text-white',
    title: 'Fiches marquées comme répétées (doublon de numéro) — clique pour filtrer',
  },
  {
    // Seau **dérivé** (`phone` vide) : recoupe les autres (un prospect sans
    // numéro est aussi `AVAILABLE` ou blacklisté) — sélection unique dans
    // l'UI, donc le compteur = lignes rendues après clic reste exact.
    key: 'SANS_TELEPHONE',
    tone: 'red',
    label: 'Sans tel..',
    tileLabel: 'Sans tél.',
    icon: PhoneOff,
    iconClass: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
    activeClass: 'border border-transparent bg-rose-600',
    activeFg: 'text-white',
    title: 'Prospects sans numéro de téléphone (statut dérivé) — clique pour filtrer',
  },
]

/** Badges servis par le paramètre `status` (les autres : `reservation_status`). */
export const CLIENT_STATUS_KEYS = ['AVAILABLE', 'BLACKLISTED', 'SANS_TELEPHONE', 'REPETE']

/**
 * Toutes les valeurs filtrables de la barre (clés « statut affiché ») : sert
 * à la Grande liste admin pour valider au montage le filtre pré-appliqué
 * par les cartes KPI du tableau de bord (`/clients-historique?status=…`).
 */
export const STATUS_FILTER_KEYS = STATUS_PILLS.map((pill) => pill.key)
