import StatTile, { StatMeter } from '@/pages/shared/components/StatTile/index.jsx'

/**
 * Panneau de tuiles KPI du tableau de bord — la maquette commune aux deux
 * tableaux (gestionnaire et commercial) : un titre à gauche, une précision en
 * gras léger à droite (périmètre / instantané), la grille de tuiles pastel, et
 * une jauge dégradée en pied de panneau.
 *
 * Le panneau ne décide **rien** : il affiche les tuiles que la page lui donne
 * (libellé, valeur déjà formatée, teinte sémantique, destination). Une tuile
 * sans `to` ni `onClick` reste informative.
 *
 * @param {string}  title   titre du panneau (« Grande liste », « Période »…).
 * @param {string}  [caption] mention discrète à droite du titre.
 * @param {Array}   tiles   `{ key?, label, value, icon, tone, to, onClick, title }`
 *                         — `key` de liste optionnel (le libellé sert de repli).
 * @param {boolean} [loading] rend un squelette du même gabarit.
 * @param {{label: string, ratio: number}|null} [meter] jauge de pied de panneau.
 */
export default function DashboardStatTiles({ title, caption, tiles = [], loading = false, meter }) {
  return (
    <section className="rounded-2xl bg-card p-5 text-card-foreground shadow-sm sm:p-6">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 className="text-lg font-bold tracking-tight">{title}</h2>
        {caption ? <p className="text-xs text-muted-foreground">{caption}</p> : null}
      </div>

      {loading ? (
        <div className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5" aria-hidden="true">
          {Array.from({ length: Math.max(tiles.length, 4) }).map((_, i) => (
            <div key={i} className="h-[168px] animate-pulse rounded-2xl bg-muted" />
          ))}
        </div>
      ) : (
        <div className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
          {tiles.map(({ key, ...tile }) => (
            <StatTile key={key ?? tile.label} {...tile} />
          ))}
        </div>
      )}

      {!loading && meter ? <StatMeter label={meter.label} ratio={meter.ratio} /> : null}
    </section>
  )
}
