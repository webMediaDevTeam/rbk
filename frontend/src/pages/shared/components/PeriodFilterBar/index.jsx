import { RotateCcw } from 'lucide-react'
import Select from '@/components/ui/select'
import Button from '@/components/ui/button.jsx'
import {
  MONTH_LABELS,
  countWeeks,
  daysInMonth,
  weekRange,
  periodLabel,
} from '@/pages/shared/dashboard/filters.js'

const PRESETS = [
  { value: 'all', label: "Tout l'historique" },
  { value: 'today', label: 'Ce jour' },
  { value: 'last3days', label: 'Derniers 3 jours' },
  { value: 'week', label: 'Cette semaine' },
  { value: 'month', label: 'Ce mois' },
  { value: 'last6months', label: 'Derniers 6 mois' },
  { value: 'year', label: 'Cette année' },
]

/**
 * Barre de **période** d'une liste de clients, en une seule carte compacte :
 * le menu de raccourcis, la cascade Année → Mois → Semaine → Jour, «
 * Réinitialiser », et une mention qui dit clairement ce que la fenêtre borne.
 *
 * Conçue pour les écrans où la liste a une **histoire** (onglet « Historique »
 * de la fiche employé comme de la fiche entreprise — les deux pages partagent
 * déjà `<HistoryList>` et `<ProspectKpis>`), sur le même vocabulaire que le
 * barreau du tableau de bord : mêmes tokens de `preset`, mêmes `range` roulants,
 * même découpage des semaines du mois (1 = jours 1–7, dernière partielle),
 * mêmes règles normalisées côté serveur (`App\Support\PeriodFilter`).
 *
 * Ce composant est **présentationnel** : l'état vient de `usePeriodFilters()`.
 *
 * @param {object} props
 * @param {number[]} [props.years] années proposées (celles où le périmètre a de
 *        l'activité, renvoyées par l'API) ; l'année courante y est toujours
 *        ajoutée pour que le menu ne soit jamais vide.
 * @param {boolean} [props.isDefault] aucune période posée → « tout
 *        l'historique » : aucun paramètre envoyé, et « Réinitialiser » inactif.
 * @param {string} [props.scope] ce que la fenêtre borne, dans la mention
 *        d'en-tête (ex. « la liste et ses badges »).
 */
export default function PeriodFilterBar({
  years = [],
  year,
  month,
  week,
  day,
  range = null,
  preset = '',
  isDefault = false,
  scope = 'la liste et ses badges',
  onYearChange,
  onMonthChange,
  onWeekChange,
  onDayChange,
  onPresetChange,
  onReset,
}) {
  const weeks = month ? countWeeks(year, month) : 0
  const days = month ? daysInMonth(year, month) : 0

  // Le menu ne doit jamais perdre l'année affichée (un périmètre sans activité
  // cette année-là, par exemple) : on la remet en tête si elle manque.
  const yearOptions = years.includes(year) ? years : [year, ...years]

  return (
    <div className="rounded-2xl bg-card p-4 text-card-foreground shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
        <span className="text-sm font-medium text-muted-foreground">
          Période — borne {scope}
        </span>

        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={onReset}
          disabled={isDefault}
          title="Retirer la période : la liste retrouve tout l'historique"
        >
          <RotateCcw className="h-3.5 w-3.5" />
          Réinitialiser
        </Button>
      </div>

      <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2">
        <Select
          value={preset ?? ''}
          onChange={(e) => e.target.value && onPresetChange(e.target.value)}
          className="w-52 shrink-0"
          aria-label="Choisir une période"
        >
          <option value="">Raccourcis de période</option>
          {PRESETS.map((p) => (
            <option key={p.value} value={p.value}>
              {p.label}
            </option>
          ))}
        </Select>

        <label className="flex items-center gap-2 text-sm">
          <span className="font-medium">Année</span>
          <Select
            value={year ?? ''}
            onChange={(e) => onYearChange(Number(e.target.value))}
            className="w-24 shrink-0"
            aria-label="Année de l'historique"
          >
            {yearOptions.map((y) => (
              <option key={y} value={y}>
                {y}
              </option>
            ))}
          </Select>
        </label>

        <label className="flex items-center gap-2 text-sm">
          <span className="font-medium">Mois</span>
          <Select
            value={month ?? ''}
            onChange={(e) => onMonthChange(e.target.value ? Number(e.target.value) : null)}
            className="w-36 shrink-0"
            aria-label="Mois de l'historique"
          >
            <option value="">Tous</option>
            {MONTH_LABELS.map((label, i) => (
              <option key={label} value={i + 1}>
                {label}
              </option>
            ))}
          </Select>
        </label>

        <label className="flex items-center gap-2 text-sm">
          <span className="font-medium">Semaine</span>
          <Select
            value={week ?? ''}
            onChange={(e) => onWeekChange(e.target.value ? Number(e.target.value) : null)}
            className="w-44 shrink-0"
            aria-label="Semaine du mois choisi"
            disabled={!month}
            title="Semaines du mois choisi : 1 = jours 1–7, 2 = jours 8–14…, la dernière est partielle"
          >
            <option value="">Toutes</option>
            {Array.from({ length: weeks }, (_, i) => i + 1).map((n) => {
              const [from, to] = weekRange(year, month, n)

              return (
                <option key={n} value={n}>
                  {`Semaine ${n} (${from}–${to})`}
                </option>
              )
            })}
          </Select>
        </label>

        <label className="flex items-center gap-2 text-sm">
          <span className="font-medium">Jour</span>
          <Select
            value={day ?? ''}
            onChange={(e) => onDayChange(e.target.value ? Number(e.target.value) : null)}
            className="w-28 shrink-0"
            aria-label="Jour du mois choisi"
            disabled={!month}
            title="Un seul jour du mois choisi — retire la semaine sélectionnée"
          >
            <option value="">Tous</option>
            {Array.from({ length: days }, (_, i) => i + 1).map((d) => (
              <option key={d} value={d}>
                {d}
              </option>
            ))}
          </Select>
        </label>
      </div>

      {/* Mention d'échelle : ce que la fenêtre est en train de borner. La
          période se lit sur la **date de la note d'appel** du périmètre, donc
          « tout l'historique » est explicite plutôt que implicite. */}
      <p className="mt-2 text-xs text-muted-foreground">
        {isDefault
          ? "Tout l'historique — aucune fenêtre de période appliquée."
          : `Fenêtre appliquée : ${periodLabel({ year, month, week, day, range })} (sur la date de l'appel).`}
      </p>
    </div>
  )
}
