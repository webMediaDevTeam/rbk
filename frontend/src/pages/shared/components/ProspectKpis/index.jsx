import { CalendarCheck, Clock, Hourglass, PhoneCall, PhoneIncoming, Users } from 'lucide-react'
import KpiPill, { KpiBar, formatCount } from '@/pages/shared/components/KpiPill/index.jsx'
import { useProspectKpis } from './useProspectKpis.js'

const fmt = formatCount
const ratio = (part, whole) => `${fmt(part)} / ${fmt(whole)}`
const pct = (part, whole) => (whole > 0 ? `${Math.round((part / whole) * 100)} %` : '0 %')

/**
 * Overview KPI — badges compacts, chiffres globaux du système :
 *  1. Prospects (hors liste noire) + disponibles + liste noire
 *  2. Réservés (total)
 *  3. Réservés & traités   (le commercial a appelé / changé le statut)
 *  4. Réservés & non traités (aucun appel pour l'instant)
 *  5. Succès / traités      (issues « OUI »)
 *  6. En cours / traités    (traitement en cours : BV / à rappeler)
 *
 * Un badge dont le compte principal vaut **0 n'est pas affiché**, et les
 * segments de suffixe à 0 (« 0 noir », « 0 % ») sont retirés : la barre ne
 * montre que des chiffres porteurs d'information.
 */
export default function ProspectKpis() {
  const { kpis, isLoading, isError } = useProspectKpis()

  if (isError) return null

  if (isLoading || !kpis) {
    return (
      <div className="flex flex-wrap gap-2" aria-hidden="true">
        {Array.from({ length: 6 }).map((_, i) => (
          <div key={i} className="h-8 w-40 animate-pulse rounded-full border border-border/60 bg-card" />
        ))}
      </div>
    )
  }

  const { prospects, reserved, processed } = kpis

  const hasBlacklist = prospects.blacklisted > 0

  const pills = [
    {
      primary: prospects.not_blacklisted,
      label: 'Prospects',
      value: fmt(prospects.not_blacklisted),
      suffix: [
        prospects.available > 0 && `${fmt(prospects.available)} dispo`,
        hasBlacklist && `${fmt(prospects.blacklisted)} noir${prospects.blacklisted > 1 ? 's' : ''}`,
      ].filter(Boolean).join(' · '),
      suffixClass: hasBlacklist ? 'text-destructive' : 'text-muted-foreground',
      icon: Users,
      iconClass: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
      title: `Prospects hors liste noire — ${fmt(prospects.system)} prospects au total`,
    },
    {
      primary: reserved.total,
      label: 'Réservés',
      value: fmt(reserved.total),
      icon: CalendarCheck,
      iconClass: 'bg-primary/10 text-primary',
      title: 'Total des prospects au statut Réservé',
    },
    {
      primary: reserved.processed,
      label: 'Réservés traités',
      value: ratio(reserved.processed, reserved.total),
      icon: PhoneCall,
      iconClass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
      title: 'Réservés par le commercial, avec au moins un appel / changement de statut',
    },
    {
      primary: reserved.not_processed,
      label: 'Réservés non traités',
      value: ratio(reserved.not_processed, reserved.total),
      icon: Hourglass,
      iconClass: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
      title: 'Réservés mais sans aucun appel pour le moment',
    },
    {
      primary: processed.success,
      label: 'Succès / traités',
      value: ratio(processed.success, processed.total),
      suffix: processed.success > 0 ? pct(processed.success, processed.total) : '',
      suffixClass: 'text-emerald-600 dark:text-emerald-400',
      icon: PhoneIncoming,
      iconClass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
      title: 'Issues « OUI » parmi les prospects traités',
    },
    {
      primary: processed.in_progress,
      label: 'En cours / traités',
      value: ratio(processed.in_progress, processed.total),
      suffix: processed.in_progress > 0 ? pct(processed.in_progress, processed.total) : '',
      suffixClass: 'text-amber-600 dark:text-amber-400',
      icon: Clock,
      iconClass: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
      title: 'Traitement en cours (BV / à rappeler) parmi les prospects traités',
    },
  ].filter((pill) => pill.primary > 0)

  if (pills.length === 0) return null

  return (
    <KpiBar>
      {pills.map((pill) => (
        <KpiPill key={pill.label} {...pill} />
      ))}
    </KpiBar>
  )
}
