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
 * l'origine active (`?source=`) **et la période du dashboard**
 * (`?year=&month=&week=&day=`).
 *
 * Sous période, seules les tuiles d'issue **Oui → Blacklist** se bornent à la
 * fenêtre, sur la **date de la note** du client (note d'issue du type créé
 * pendant la période) ; « Tous », « Libre » et « Sans tel. » restent des
 * **instantanés fixes**. Sans période (badges de la liste elle-même), tous les
 * compteurs sont instantanés.
 *
 * Chaque tuile mène à la Grande liste **pré-filtrée** sur son statut
 * (`/clients-historique?status=CLE`) ; l'origine active, elle, se lit dans
 * `localStorage` des deux côtés (`useSourcePreference`), donc le clic garde le
 * même périmètre — la liste n'étant pas bornée par la période, ses lignes
 * peuvent excéder le chiffre de la tuile quand une période est posée.
 *
 * Chaque tuile porte aussi sa **part** du total de la liste (pourcentage +
 * jauge) : « Tous » est à 100 % par définition, les autres se lisent dessus. La
 * part de « Sans tél. » peut chevaucher les autres (statut dérivé) — la somme
 * des neuf n'atteint donc pas 100 %.
 *
 * @param {string} [source] origine active (`''` = « Toutes »).
 * @param {number} [year]  période du dashboard — posée, seules les tuiles
 * @param {number} [month] d'issue Oui → Blacklist se bornent aux notes de la
 * @param {number} [week]  fenêtre ; « Tous » / « Libre » / « Sans tel. »
 * @param {number} [day]   restent fixes (jour seul > semaine > mois > année).
 * @param {string} [range] fenêtre roulante (`last3days` / `last6months`) —
 *                         posée, elle **prime** sur la cascade (issue bornée
 *                         par la date de la note dans la fenêtre glissante).
 */
export default function DashboardStatusCards({ source = '', year, month, week, day, range }) {
  const { kpis, isLoading, isError } = useProspectKpis(undefined, {
    filters: { source, year, month, week, day, range },
  })

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
      tiles={tiles}
      loading={isLoading}
      meter={kpis ? { label: 'Contacts libres', ratio: total > 0 ? libres / total : 0 } : null}
    />
  )
}
