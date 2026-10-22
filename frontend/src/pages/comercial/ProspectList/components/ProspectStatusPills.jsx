import { CircleCheck, Copy, CopyCheck, Info } from 'lucide-react'
import KpiPill, { KpiBar, formatCount } from '@/pages/shared/components/KpiPill/index.jsx'

/**
 * Barre de filtres « Libre / Double / Info / num Répétés » de la Grande
 * liste **commerciale** — 4 pastilles en sélection unique, même vocabulaire de
 * couleurs que la barre admin (`ProspectKpis`) et les tuiles du tableau de
 * bord : *Libre* bleu, *Double* couleur primaire de l'app,
 * *Info* bleu ciel, *num Répétés* fuchsia.
 *
 * Pas de pastille « Tous » : l'absence de filtre (aucune pastille active)
 * rend déjà toutes les lignes de la liste ; re-cliquer la pastille active la
 * retire.
 *
 * Les compteurs viennent de `data.badges` de `GET clients` : le **périmètre de
 * l'employé** (sa source, les prospects qu'il détient, son privilège §7.1),
 * filtres de recherche inclus et sélection de badge ignorée — le chiffre d'une
 * pastille vaut donc le nombre de lignes rendues après son clic (invariant
 * « compteur = lignes », docs/RULES.md §9).
 *
 * La liste commerciale ne contient que ces sortes de lignes : un prospect
 * libre à réserver, les « Double » / « Info » que l'employé a **détenus**
 * (issues qui le gardent tenu, §3), et les fiches marquées comme **répétées**
 * (doublons rattachés à un original, statut `REPETE`) — filtrables pour les
 * repérer sous le même angle que la pastille admin. Les autres statuts (Oui /
 * Non / BV / À rappeler / Blacklist / Sans téléphone) n'y ont pas leur place —
 * ils sont déjà filtrables côté admin et dans « Mes listes ».
 *
 * Sans le privilège de libération, les « Double » sortent de la liste : la
 * pastille affiche 0 et le clic ne rend rien — fidèle à ce que voit l'employé.
 *
 * @param {object}   badges                `data.badges` de `GET clients` ;
 *                                         `null`/`undefined` = chargement →
 *                                         squelette de 4 pastilles.
 * @param {string|null} statusFilter       valeur active (`null` = « Tous »).
 * @param {(value: string|null) => void} onStatusFilterChange
 */
export default function ProspectStatusPills({ badges, statusFilter, onStatusFilterChange }) {
  if (!badges) {
    return (
      <div className="flex flex-wrap gap-1.5" aria-hidden="true">
        {Array.from({ length: 5 }).map((_, i) => (
          <div key={i} className="h-7 w-28 animate-pulse rounded-full border border-border/60 bg-card" />
        ))}
      </div>
    )
  }

  const counts = badges.by_display_status ?? {}

  // `key` = valeur du paramètre serveur `status` ; la valeur « aucun filtre »
  // (Tous) n'a plus de pastille dédiée — retirer le filtre = re-cliquer la
  // pastille active (ou les autres écrans qui remettent `statusFilter` à null).
  const pills = [
    {
      key: 'AVAILABLE',
      label: 'Libre',
      value: counts.AVAILABLE ?? 0,
      icon: CircleCheck,
      iconClass: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
      activeClass: 'border border-transparent bg-blue-600',
      title: 'Prospects libres à réserver — cliquez pour filtrer',
    },
    {
      key: 'DOUBLE',
      label: 'Double',
      value: counts.DOUBLE ?? 0,
      icon: Copy,
      iconClass: 'bg-primary/10 text-primary',
      activeClass: 'border border-transparent bg-primary',
      title: 'Prospects pris « Double » que vous détenez encore — cliquez pour filtrer',
    },
    {
      key: 'INFO',
      label: 'Info',
      value: counts.INFO ?? 0,
      icon: Info,
      iconClass: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
      activeClass: 'border border-transparent bg-sky-600',
      title: 'Prospects « Info » (renseigné, pas d’offre) que vous détenez — cliquez pour filtrer',
    },
    {
      // Fiches rattachées à un doublon d'origine (« marquer comme répété »,
      // onglet « Numéros répétés » de la fiche) — même teinte fuchsia que la
      // pastille admin « num Répétés ».
      key: 'REPETE',
      label: 'num Répétés',
      value: counts.REPETE ?? 0,
      icon: CopyCheck,
      iconClass: 'bg-fuchsia-500/10 text-fuchsia-600 dark:text-fuchsia-400',
      activeClass: 'border border-transparent bg-fuchsia-600',
      title: 'Fiches marquées comme répétées (doublon de numéro) — cliquez pour filtrer',
    },
  ]

  return (
    <KpiBar dense>
      {pills.map(({ key, label, value, icon, iconClass, activeClass, title }) => {
        const active = statusFilter === key

        return (
          <KpiPill
            key={label}
            label={label}
            value={formatCount(value)}
            icon={icon}
            iconClass={iconClass}
            activeClass={activeClass}
            activeFg="text-white"
            title={title}
            compact
            active={active}
            onClick={() => onStatusFilterChange(active ? null : key)}
          />
        )
      })}
    </KpiBar>
  )
}
