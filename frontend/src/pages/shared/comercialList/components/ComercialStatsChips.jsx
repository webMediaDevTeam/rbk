import { Link } from 'react-router-dom'
import { BellRing, PhoneIncoming, Users, Voicemail } from 'lucide-react'

/**
 * Puces d'activité d'un employé (page « Employés ») — réplique compacte des
 * cartes KPI cliquables du tableau de bord : le chiffre mène aux lignes
 * correspondantes (historique de l'employé filtré, même sémantique que les
 * badges de statut « compteur = lignes rendues »).
 *
 *  - `stats` : `{ clients, bv, rappel, oui }` (`GET /commercials/stats`) ou
 *    `null` tant que non chargé — les puces rendent alors 0 ;
 *  - `linked` = false pour les **opérateurs** (pas de page de détail
 *    employé) et pendant le chargement : puces muettes, non cliquables ;
 *  - OUI n'a **jamais** de lien (volonté produit) — c'est un compteur de
 *    notes d'issue, pas une liste.
 */
const CHIPS = [
  {
    key: 'clients',
    label: 'Clients',
    icon: Users,
    className: 'bg-primary/10 text-primary',
    query: '?tab=historique',
    title: 'Clients appelés par cet employé — voir sa liste',
  },
  {
    key: 'bv',
    label: 'BV',
    icon: Voicemail,
    className: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    query: '?tab=historique&status=BV_VOICEMAIL',
    title: 'Réservation courante « BV » — cliquer filtre sa liste',
  },
  {
    key: 'rappel',
    label: 'Rappel',
    icon: BellRing,
    className: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
    query: '?tab=historique&status=CALL_BACK',
    title: 'Réservation courante « À rappeler » — cliquer filtre sa liste',
  },
  {
    key: 'oui',
    label: 'Oui',
    icon: PhoneIncoming,
    className: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    query: null,
    title: "Notes d'issue « Oui » émises par cet employé",
  },
]

export default function ComercialStatsChips({ stats, userId, linked = true }) {
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      {CHIPS.map(({ key, label, icon: Icon, className, query, title }) => {
        const value = stats?.[key] ?? 0
        const inner = (
          <>
            <Icon className="h-3.5 w-3.5" />
            <span className="tabular-nums">{value}</span>
            <span className="text-[10px] font-normal uppercase tracking-wide opacity-70">{label}</span>
          </>
        )
        const classes = `inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold ${className}`
        const href = linked && query ? `/comercialDetail/${userId}${query}` : null

        return href ? (
          <Link
            key={key}
            to={href}
            title={title}
            className={`${classes} transition-shadow hover:ring-2 hover:ring-ring/40`}
          >
            {inner}
          </Link>
        ) : (
          <span key={key} title={title} className={classes}>
            {inner}
          </span>
        )
      })}
    </div>
  )
}
