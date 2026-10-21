import { RotateCcw } from 'lucide-react'
import Select from '@/components/ui/select'
import Button from '@/components/ui/button.jsx'
import SourceTabs from '@/pages/shared/components/SourceTabs/index.jsx'
import { MONTH_LABELS, countWeeks, weekRange } from '../filters.js'

/**
 * Filtre **global** du tableau de bord, sur une seule rangée :
 * Année → Mois → Semaine (+ le bouton « Réinitialiser »).
 *
 * Le parent applique la cascade (choix d'une année ⇒ mois et semaine remis à
 * « tous » ; choix d'un mois ⇒ semaine remise à « toutes ») — la semaine
 * n'est proposée qu'une fois le mois choisi. Les semaines suivent le découpage
 * serveur : 1 = jours 1–7, 2 = jours 8–14…, la dernière est partielle.
 * Le premier sélecteur « Période » est un menu de raccourcis (Cette semaine /
 * Ce mois-ci / Cette année) qui pose les trois sélecteurs d'un coup ; il
 * reste sur son invite une fois l'action appliquée. Le bouton
 * « Réinitialiser » revient au filtre par défaut (année courante, mois et
 * semaine « tous ») — il est inactif quand on y est déjà. Il ne touche **pas**
 * à l'origine : celle-ci est une préférence mémorisée, pas un réglage de
 * période.
 *
 * À droite de la même rangée, **gestionnaires seulement** (`showSource`) : les
 * onglets « Source » de la Grande liste (`<SourceTabs>`, privilège
 * `clients:filter-source`). La sélection est **partagée** (localStorage) avec
 * la liste : elle borne toutes les cartes liées aux clients d'ici **et** le
 * périmètre trouvé en cliquant une tuile de statut.
 */
export default function DashboardFilterBar({
  years = [],
  year,
  month,
  week,
  onYearChange,
  onMonthChange,
  onWeekChange,
  onPresetChange,
  onReset,
  showSource = false,
  source = '',
  onSourceChange,
}) {
  const weeks = month ? countWeeks(year, month) : 0
  const isDefault =
    month === null && week === null && year === new Date().getFullYear()

  return (
    <div className="flex flex-wrap items-center gap-x-4 gap-y-3 rounded-2xl bg-card p-4 text-card-foreground shadow-sm">
      <label className="flex items-center gap-2 text-sm">
        <span className="font-medium">Période</span>
        <Select
          value=""
          onChange={(e) => e.target.value && onPresetChange(e.target.value)}
          className="w-40 shrink-0"
          aria-label="Raccourcis de période"
        >
          <option value="">Choisir…</option>
          <option value="week">Cette semaine</option>
          <option value="month">Ce mois-ci</option>
          <option value="year">Cette année</option>
        </Select>
      </label>

      <label className="flex items-center gap-2 text-sm">
        <span className="font-medium">Année</span>
        <Select
          value={year ?? ''}
          onChange={(e) => onYearChange(Number(e.target.value))}
          className="w-24 shrink-0"
          aria-label="Année du tableau de bord"
        >
          {years.map((y) => (
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
          aria-label="Mois du tableau de bord"
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
          aria-label="Semaine du tableau de bord"
          disabled={!month}
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

      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={onReset}
        disabled={isDefault}
        title="Retour à l'année courante, tous mois et toutes semaines"
      >
        <RotateCcw className="h-3.5 w-3.5" />
        Réinitialiser
      </Button>

      {/* Origine active — poussée à droite du barreau (le composant ne rend
          rien sans le privilège `clients:filter-source`). */}
      {showSource && (
        <div className="ml-auto">
          <SourceTabs source={source} onChange={onSourceChange} />
        </div>
      )}
    </div>
  )
}
