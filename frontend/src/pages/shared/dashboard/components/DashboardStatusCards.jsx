import { Users } from 'lucide-react'
import { STATUS_PILLS } from '@/pages/shared/components/ProspectKpis/status-pills.js'
import { formatCount } from '@/pages/shared/components/KpiPill/index.jsx'
import { useProspectKpis } from '@/pages/shared/components/ProspectKpis/useProspectKpis.js'
import DashboardStatTiles from './DashboardStatTiles.jsx'

const LIST_HREF = '/clients-historique'

/**
 * Panneau « Grande liste » du tableau de bord gestionnaire : les **mêmes
 * compteurs que les 10 badges de statut de la liste** (`Tous` + `Libre` →
 * `Sans tel.`, docs/RULES.md §9), lus sur `GET clients/overview` sous
 * l'origine active (`?source=`) — donc identiques à ceux que l'admin verra en
 * arrivant sur la liste.
 *
 * Ce sont des **instantanés** : la période (Année / Mois / Semaine) ne les
 * borne pas, seule l'origine le fait — c'est dit dans la mention du titre.
 *
 * Chaque tuile mène à la Grande liste **pré-filtrée** sur son statut
 * (`/clients-historique?status=CLE`) ; l'origine active, elle, se lit dans
 * `localStorage` des deux côtés (`useSourcePreference`), donc le clic garde le
 * même périmètre.
 *
 * Chaque tuile porte aussi sa **part** du total de la liste (pourcentage +
 * jauge) : « Tous » est à 100 % par définition, les autres se lisent dessus. La
 * part de « Sans tél. » peut chevaucher les autres (statut dérivé) — la somme
 * des neuf n'atteint donc pas 100 %.
 *
 * @param {string} [source] origine active (`''` = « Toutes »).
 */
export default function DashboardStatusCards({ source = '' }) {
  const { kpis, isLoading, isError } = useProspectKpis(undefined, { filters: { source } })

  if (isError) return null

  const total = kpis?.prospects?.system ?? 0
  const libres = kpis?.by_display_status?.AVAILABLE ?? 0
  const countOf = (key) => kpis?.by_display_status?.[key] ?? 0

  const tiles = kpis
    ? [
        {
          key: 'all',
          label: 'Tous',
          count: total,
          icon: Users,
          tone: 'violet',
          to: LIST_HREF,
          title: 'Ouvre la Grande liste sans filtre de statut',
        },
        ...STATUS_PILLS.map((pill) => ({
          key: pill.key,
          label: pill.tileLabel ?? pill.label,
          count: countOf(pill.key),
          icon: pill.icon,
          tone: pill.tone,
          to: `${LIST_HREF}?status=${pill.key}`,
          title: `${pill.label} — ouvre la Grande liste filtrée${source ? ` (origine « ${source} »)` : ''}`,
        })),
      ].map((tile) => ({
        ...tile,
        value: formatCount(tile.count),
        ratio: total > 0 ? tile.count / total : 0,
      }))
    : []

  return (
    <DashboardStatTiles
      title="Grande liste"
      caption={`Instantané · toutes périodes, origine ${source ? `« ${source} »` : '« toutes »'}`}
      tiles={tiles}
      loading={isLoading}
      meter={kpis ? { label: 'Contacts libres', ratio: total > 0 ? libres / total : 0 } : null}
    />
  )
}
