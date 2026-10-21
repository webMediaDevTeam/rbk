import { Link } from 'react-router-dom'
import { TILE_TONES } from './tones.js'

/**
 * Part en pourcentage français : une décimal suffit au-dessus de 1 %, en
 * dessous on garde deux décimales (1 client sur 53 000 doit rester lisible).
 */
const formatPercent = (pct) =>
  new Intl.NumberFormat('fr-FR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: pct > 0 && pct < 1 ? 2 : 1,
  }).format(pct)

/**
 * Tuile KPI du tableau de bord : pastille d'icône pleine, grand chiffre,
 * libellé **de la même teinte**, jauge de part en pied de tuile, sur fond
 * pastel liseré.
 *
 * Cliquable via `to` (vrai `<Link>` — le clic droit / « ouvrir dans un
 * onglet » fonctionne, contrairement à un `onClick`) ou `onClick`. Sans l'un
 * des deux, la tuile est purement informative.
 *
 * @param {keyof TILE_TONES} [tone] teinte sémantique (`violet` par défaut).
 * @param {Function}         [icon] composant icône lucide (aussi reprise en
 *                                  filigrane pivoté à 45° dans le coin supérieur droit).
 * @param {string|number}    value  valeur déjà formatée.
 * @param {string}           label  libellé sous le chiffre.
 * @param {number}           [ratio] part 0→1 affichée en pourcentage + jauge.
 * @param {string}           [to]   destination (route + query).
 * @param {Function}         [onClick] alternative sans navigation.
 * @param {string}           [title] infobulle (destination de la tuile).
 */
export default function StatTile({ tone = 'violet', icon: Icon, value, label, ratio, to, onClick, title }) {
  const palette = TILE_TONES[tone] ?? TILE_TONES.violet
  // Part affichée : la valeur brute est rendue telle quelle (une liste peut
  // dépasser son dénominateur, ex. « OUI » d'une période) ; seule la jauge est
  // écrêtée à 100 %.
  const pct = Number.isFinite(ratio) ? ratio * 100 : null
  const width = pct === null ? 0 : Math.min(Math.max(pct, pct > 0 ? 2 : 0), 100)

  const className = [
    'group relative flex flex-col overflow-hidden rounded-2xl border p-5 text-left transition-all',
    palette.tile,
    // Une tuile cliquable se « soulève » au survol ; les autres restent plates.
    to || onClick ? 'hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring' : '',
  ].filter(Boolean).join(' ')

  const body = (
    <>
      {/* Filigrane décoratif : la même icône, géante, atténuée et pivotée de
          45° dans le coin supérieur droit (`aria-hidden`, non interactive). */}
      {Icon ? (
        <Icon
          aria-hidden="true"
          className={`pointer-events-none absolute right-3 top-3 h-16 w-16 rotate-45 opacity-[0.12] ${palette.text}`}
        />
      ) : null}
      {Icon ? (
        <span className={`relative z-10 flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-white shadow-sm ${palette.chip}`}>
          <Icon className="h-5 w-5" />
        </span>
      ) : null}
      <span className={`relative z-10 mt-5 text-4xl font-extrabold leading-none tracking-tight tabular-nums ${palette.text}`}>
        {value}
      </span>
      <div className="relative z-10 mt-2 flex items-baseline justify-between gap-2">
        <span className={`text-sm font-medium ${palette.text}`}>{label}</span>
        {pct !== null ? (
          <span className="text-xs font-semibold tabular-nums text-muted-foreground">{`${formatPercent(pct)} %`}</span>
        ) : null}
      </div>
      {pct !== null ? (
        <div className="relative z-10 mt-auto pt-4">
          <div
            className="h-1.5 w-full overflow-hidden rounded-full bg-foreground/10 dark:bg-white/15"
            role="progressbar"
            aria-valuenow={Math.round(pct)}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-label={label}
          >
            <div
              className={`h-full rounded-full transition-[width] duration-500 ${palette.chip}`}
              style={{ width: `${width}%` }}
            />
          </div>
        </div>
      ) : null}
    </>
  )

  if (to) {
    return (
      <Link to={to} title={title} className={className}>
        {body}
      </Link>
    )
  }

  if (onClick) {
    return (
      <button type="button" onClick={onClick} title={title} className={className}>
        {body}
      </button>
    )
  }

  return (
    <div title={title} className={className}>
      {body}
    </div>
  )
}

/**
 * Jauge dégradée de bas de panneau (primaire → émeraude) : le rapport lu à
 * droite est celui de la valeur sur le total du périmètre, formaté en
 * pourcentage français (« 99,8 % »).
 *
 * @param {string}  label  légende à gauche (« Contacts libres »).
 * @param {number}  ratio  proportion 0→1.
 * @param {string}  [caption] texte à droite (défaut : le pourcentage).
 */
export function StatMeter({ label, ratio, caption }) {
  const clamped = Math.max(0, Math.min(1, Number.isFinite(ratio) ? ratio : 0))
  const percent = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 }).format(clamped * 100)

  return (
    <div className="mt-6">
      <div className="flex items-center justify-between gap-3 text-xs text-muted-foreground">
        <span>{label}</span>
        <span className="font-semibold tabular-nums text-foreground">{caption ?? `${percent} %`}</span>
      </div>
      <div
        className="mt-2 h-2 w-full overflow-hidden rounded-full bg-muted"
        role="progressbar"
        aria-valuenow={Math.round(clamped * 100)}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-label={label}
      >
        <div
          className="h-full rounded-full bg-linear-to-r from-indigo-500 via-primary to-emerald-500 transition-[width] duration-500"
          style={{ width: `${Math.max(clamped * 100, clamped > 0 ? 1.5 : 0)}%` }}
        />
      </div>
    </div>
  )
}
