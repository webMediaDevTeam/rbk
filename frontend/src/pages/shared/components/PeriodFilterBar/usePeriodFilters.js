import { useState } from 'react'

/**
 * Cascade de période **partagée** (fiche employé, fiche entreprise) : même
 * grammaire que le barreau du tableau de bord — raccourcis calendaires, fenêtre
 * roulante, puis Année → Mois → Semaine → Jour — et **mêmes règles serveur**
 * (`App\Support\PeriodFilter`, paramètres `year` / `month` / `week` / `day` /
 * `range`).
 *
 * Différence notable avec le dashboard : ici l'absence de période est un état
 * **significatif** — « tout l'historique ». Tant que rien n'est **choisi**, `params`
 * est vide et l'API répond en instantané complet (comportement historique de
 * ces pages) ; dès qu'un réglage l'est, la liste **et** ses badges se bornent à
 * la fenêtre.
 *
 * Règles de cascade (miroir de `useDashboard` et du serveur) :
 *  - changer l'année remet mois, semaine et jour à « tous » ;
 *  - changer le mois remet semaine et jour à « tous » ;
 *  - semaine et jour sont les deux réglages **fins** du mois choisi et
 *    s'excluent mutuellement (le jour gagne) ;
 *  - semaine et jour ne s'ouvrent qu'une fois le mois choisi ;
 *  - toucher un réglage fin efface le raccourci actif (`preset`) ;
 *  - une fenêtre roulante (`last3days` / `last6months`) **prime** sur la
 *    cascade, qui est alors remise à « année courante, tout le reste tous ».
 *
 * @param {object} [options]
 * @param {(value: any) => void} [options.onChange] appelé sur chaque mutation
 *        (les pages s'en servent pour revenir à la 1re page).
 */
export function usePeriodFilters({ onChange } = {}) {
  const currentYear = new Date().getFullYear()

  const [year, setYearValue] = useState(currentYear)
  const [month, setMonthValue] = useState(null)
  const [week, setWeekValue] = useState(null)
  const [day, setDayValue] = useState(null)
  const [range, setRange] = useState(null)
  // Raccourci de période affiché par le menu ; `''` dès qu'on règle la période
  // à la main, `'all'` (état d'entrée) = « tout l'historique », aucune borne
  // envoyée.
  const [preset, setPreset] = useState('all')
  // Un réglage **choisi** borne la liste, même s'il retombe sur les valeurs par
  // défaut de la cascade (sélectionner « Année 2026 » à la main n'est pas « rien
  // posé »). Cet état évite d'inférer depuis `year`/`month`/…, ce qui ne
  // pourrait pas distinguer l'état d'entrée d'un réglage volontaire identique.
  const [touched, setTouched] = useState(false)

  const notify = () => {
    setTouched(true)
    onChange?.()
  }

  const applyQuickPeriod = (token) => {
    const now = new Date()

    if (token === 'year') {
      setYearValue(currentYear)
      setMonthValue(null)
      setWeekValue(null)
      setDayValue(null)

      return
    }

    const currentMonth = now.getMonth() + 1
    setYearValue(currentYear)

    if (token === 'month') {
      setMonthValue(currentMonth)
      setWeekValue(null)
      setDayValue(null)
    } else if (token === 'week') {
      // Découpage serveur : semaine du mois, 1 = jours 1–7, 2 = 8–14…
      setMonthValue(currentMonth)
      setWeekValue(Math.ceil(now.getDate() / 7))
      setDayValue(null)
    } else if (token === 'today') {
      setMonthValue(currentMonth)
      setWeekValue(null)
      setDayValue(now.getDate())
    }
  }

  const setYear = (value) => {
    setPreset('')
    setRange(null)
    setYearValue(value)
    setMonthValue(null)
    setWeekValue(null)
    setDayValue(null)
    notify()
  }

  const setMonth = (value) => {
    setPreset('')
    setRange(null)
    setMonthValue(value)
    setWeekValue(null)
    setDayValue(null)
    notify()
  }

  const setWeek = (value) => {
    setPreset('')
    setRange(null)
    setWeekValue(value)
    setDayValue(null)
    notify()
  }

  const setDay = (value) => {
    setPreset('')
    setRange(null)
    setDayValue(value)
    setWeekValue(null)
    notify()
  }

  const applyPreset = (token) => {
    setPreset(token)

    // « Tout l'historique » : aucune borne envoyée → instantané complet.
    if (token === 'all') {
      setRange(null)
      setYearValue(currentYear)
      setMonthValue(null)
      setWeekValue(null)
      setDayValue(null)
      setTouched(false)
      onChange?.()

      return
    }

    if (token === 'last3days' || token === 'last6months') {
      setRange(token)
      setYearValue(currentYear)
      setMonthValue(null)
      setWeekValue(null)
      setDayValue(null)
      notify()

      return
    }

    setRange(null)
    applyQuickPeriod(token)
    notify()
  }

  // « Réinitialiser » = retour à l'état d'entrée de la page : tout l'historique.
  const resetPeriod = () => {
    setPreset('all')
    setRange(null)
    setYearValue(currentYear)
    setMonthValue(null)
    setWeekValue(null)
    setDayValue(null)
    setTouched(false)
    onChange?.()
  }

  // Rien de **choisi** = « tout l'historique » : `params` reste vide, l'API
  // répond en instantané complet (comportement d'avant le filtre). Dès qu'un
  // réglage est posé — y compris l'année courante — la fenêtre est réelle.
  const params = touched
    ? range
      ? { range }
      : {
          year,
          month: month ?? undefined,
          week: week ?? undefined,
          day: day ?? undefined,
        }
    : {}

  return {
    year,
    month,
    week,
    day,
    range,
    preset,
    isDefault: !touched,
    params,
    setYear,
    setMonth,
    setWeek,
    setDay,
    applyPreset,
    resetPeriod,
  }
}
