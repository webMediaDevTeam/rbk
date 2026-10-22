import { RotateCcw } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import Select from '@/components/ui/select'
import Button from '@/components/ui/button.jsx'
import { listClientSourcesApi } from '@/api/shared.api.js'
import { useInteractionFilters } from '@/hooks/use-interaction-filters.js'
import { MONTH_LABELS, countWeeks, daysInMonth, weekRange } from '../filters.js'

/**
 * Filtre **global** du tableau de bord, organisé en **trois rangées** dans une
 * carte.
 *
 *  - **Rangée 1 — « Recherche simple par période »** : le menu de raccouris à
 *    gauche (à côté de son étiquette) et le bouton « Réinitialiser » **poussé
 *    tout à droite** de la même ligne (`justify-between`), en couleur pleine
 *    pour le distinguer. Les quatre raccouris calendaires (Ce jour / Cette
 *    semaine / Ce mois / Cette année) posent la cascade d'un coup ; les deux
 *    **fenêtres roulantes** (Derniers 3 jours / Derniers 6 mois) posent une
 *    plage glissante ancrée sur aujourd'hui (paramètre `range`, qui prime sur
 *    la cascade). Le menu reste figé sur le raccourci choisi (`preset`) tant
 *    qu'on ne règle pas la période à la main.
 *  - **Rangée 2 — « Recherche spécifique »** : l'étiquette, puis les réglages
 *    fins **personnalisés** (Année → Mois → Semaine → Jour) juste en dessous.
 *    Toucher l'un d'eux efface le raccourci actif. La semaine suit le découpage
 *    serveur : 1 = jours 1–7, 2 = jours 8–14…, la dernière est partielle ; elle
 *    n'est proposée qu'une fois le mois choisi. Semaine et Jour sont les deux
 *    réglages fins du mois et s'excluent mutuellement (le jour gagne côté
 *    serveur comme dans l'UI).
 *  - **Rangée 3 — Origine** : **gestionnaires seulement** (`showSource`), le
 *    répertoire `sources` rendu en **menu déroulant** (« Toutes » + une origine).
 *    La sélection est **partagée** (localStorage) avec la Grande liste : elle
 *    borne toutes les cartes liées aux clients d'ici **et** le périmètre trouvé
 *    en cliquant une tuile. « Réinitialiser » ne la touche **pas** : c'est une
 *    préférence mémorisée, pas un réglage de période.
 *  - **Rangée 4 — Entreprise / Employé** : **gestionnaires seulement**
 *    (`showEnterprise`), **comme la Grande liste**. « Entreprise » ne borne que
 *    la liste déroulante « Employé » (cascade jouée côté client, identifiant
 *    jamais envoyé) ; seul « Employé » filtre réellement les compteurs, via
 *    `employee_id` porté aux cartes (`clients/overview`, lien par notes). Les
 *    options sont restreintes à l'origine active ; « Réinitialiser » vide les deux.
 */
export default function DashboardFilterBar({
  years = [],
  year,
  month,
  week,
  day,
  range = null,
  preset = '',
  onYearChange,
  onMonthChange,
  onWeekChange,
  onDayChange,
  onPresetChange,
  onReset,
  showSource = false,
  source = '',
  onSourceChange,
  showEnterprise = false,
  enterprise = null,
  employee = null,
  onEnterpriseChange,
  onEmployeeChange,
}) {
  const weeks = month ? countWeeks(year, month) : 0
  const days = month ? daysInMonth(year, month) : 0
  const isDefault =
    !range &&
    month === null &&
    week === null &&
    day === null &&
    year === new Date().getFullYear() &&
    !enterprise &&
    !employee

  // Origines disponibles (répertoire `sources`) — chargées à la demande pour le
  // sélecteur « Origine » du gestionnaire (mêmes données que les pastilles de la
  // Grande liste, mais rendues ici en menu déroulant).
  const { data: sourcesData } = useQuery({
    queryKey: ['client-sources'],
    queryFn: () => listClientSourcesApi(),
    staleTime: 1000 * 60,
    retry: false,
    enabled: showSource,
  })
  const sources = Array.isArray(sourcesData?.data) ? sourcesData.data : []
  // Source active hors liste (onglet cliqué avant un import, ou origine retirée
  // de la base) : gardée visible, sinon la sélection en cours disparaîtrait.
  const sourceOptions =
    source && !sources.includes(source) ? [...sources, source] : sources

  // Options Entreprise / Employé (rangée 4), scopées par l'origine active —
  // mêmes données et même cascade que la Grande liste (`useInteractionFilters`) :
  // seules les entreprises / employés ayant interagi ≥ 1 fois avec un client de
  // cette origine sont proposés. L'employé est restreint à l'entreprise choisie.
  const { enterprises, employees } = useInteractionFilters(source)
  const employeeOptions = enterprise
    ? employees.filter((emp) => emp.enterprise_id === enterprise)
    : employees

  return (
    <div className="rounded-2xl bg-card p-4 text-card-foreground shadow-sm">
      {/* Rangée 1 — Recherche simple par période : le menu à gauche,
          « Réinitialiser » tout à droite (`justify-between`) et mis en avant. */}
      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
        
       <div className="mt-4">
        <span className="text-sm font-medium text-muted-foreground">
         Recherche simple par période :
        </span>
           <label className="flex items-center flex-row gap-2 text-sm mt1">
         
          <Select
            value={preset ?? ''}
            onChange={(e) => e.target.value && onPresetChange(e.target.value)}
            className="w-52 shrink-0"
            aria-label="Choisir une période"
          >
            <option value="">Choisir une période</option>
            <option value="today">Ce jour</option>
            <option value="last3days">Derniers 3 jours</option>
            <option value="week">Cette semaine</option>
            <option value="month">Ce mois</option>
            <option value="last6months">Derniers 6 mois</option>
            <option value="year">Cette année</option>
          </Select>
        </label>
       </div>

        <Button
          type="button"
          variant="default"
          size="sm"
          onClick={onReset}
          disabled={isDefault}
          title="Retour à l'année courante, tous mois, toutes semaines et tous jours"
        >
          <RotateCcw className="h-3.5 w-3.5" />
          Réinitialiser
        </Button>
      </div>

      {/* Rangée 2 — Recherche spécifique : l'étiquette, puis les sélecteurs
          Année → Mois → Semaine → Jour juste en dessous. */}
      <div className="mt-2">
        <span className="text-sm font-medium text-muted-foreground">
          Recherche spécifique
        </span>

        <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-3">
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

          <label className="flex items-center gap-2 text-sm">
            <span className="font-medium">Jour</span>
            <Select
              value={day ?? ''}
              onChange={(e) => onDayChange(e.target.value ? Number(e.target.value) : null)}
              className="w-28 shrink-0"
              aria-label="Jour du tableau de bord"
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
      </div>

      {/* Rangée 3 — Origine (gestionnaires seulement) : le répertoire `sources`
          rendu en **menu déroulant** (« Toutes » + une origine) ; la sélection
          est partagée (localStorage) avec la Grande liste et borne toutes les
          cartes liées aux clients. */}
      {showSource && (
        <div className="mt-2">
          <span className="text-sm font-medium text-muted-foreground">Source</span>

          <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-3">
            <Select
              value={source ?? ''}
              onChange={(e) => onSourceChange(e.target.value)}
              className="w-52 shrink-0"
              aria-label="Filtrer par origine"
            >
              <option value="">Toutes</option>
              {sourceOptions.map((value) => (
                <option key={value} value={value}>
                  {value}
                </option>
              ))}
            </Select>
          </div>
        </div>
      )}

      {/* Rangée 4 — Entreprise / Employé (gestionnaires seulement), **comme la
          Grande liste** : « Entreprise » borne uniquement le menu « Employé »
          (cascade client, non envoyée) ; « Employé » (`employee_id`) filtre les
          compteurs. Changer d'entreprise réinitialise l'employé choisi. */}
      {showEnterprise && (
        <div className="mt-2">
          <span className="text-sm font-medium text-muted-foreground">
            Entreprise / Employé
          </span>

          <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-3">
            <label className="flex items-center gap-2 text-sm">
              <span className="font-medium">Entreprise</span>
              <Select
                value={enterprise ?? ''}
                onChange={(e) => onEnterpriseChange?.(e.target.value)}
                className="w-52 shrink-0"
                aria-label="Restreindre la liste des employés à une entreprise"
              >
                <option value="">Toutes</option>
                {enterprises.map((en) => (
                  <option key={en.id} value={en.id}>
                    {en.name}
                  </option>
                ))}
              </Select>
            </label>

            <label className="flex items-center gap-2 text-sm">
              <span className="font-medium">Employé</span>
              <Select
                value={employee ?? ''}
                onChange={(e) => onEmployeeChange?.(e.target.value)}
                className="w-52 shrink-0"
                aria-label="Filtrer les compteurs sur un employé"
              >
                <option value="">Tous</option>
                {employeeOptions.map((emp) => (
                  <option key={emp.id} value={emp.id}>
                    {emp.name}
                  </option>
                ))}
              </Select>
            </label>
          </div>
        </div>
      )}
    </div>
  )
}
