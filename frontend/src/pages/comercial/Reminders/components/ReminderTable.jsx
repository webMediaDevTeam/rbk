import { Eye, Clock } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import Badge from '@/components/ui/badge.jsx'
import SortHeader from '@/pages/shared/components/SortHeader/index.jsx'
import UserAvatar from '@/pages/shared/components/UserAvatar/index.jsx'
import ProspectStatus from '@/pages/shared/components/ProspectStatus/index.jsx'
import { respondentsText } from '@/pages/comercial/ProspectList/components/prospectFormat'
import { cn } from '@/lib/utils.js'

/**
 * Tableau des rappels — même trame que `ProspectTable` :
 * largeurs en % (`table-fixed`, pas de défilement horizontal), textes
 * tronqués avec `title`, ligne cliquable = « Voir » l'historique du client.
 *
 * Colonne « Rappel » en plus (échéance), et **un seul contrôle en fin de
 * ligne : l'œil « Voir »** — aucune action directe (appel / terminer), la
 * page est consultative. Quand la ligne est **obsolète** (`canView()` faux :
 * rappel terminé, statut changé ou suivi plus récent), l'œil est remplacé
 * par la pastille « Obsolète ».
 */
export default function ReminderTable({
  reminders,
  startIndex = 0,
  sortBy,
  sortOrder,
  onSort,
  onView,
  canView,
  formatRecallAt,
  formatRecallFull,
  emptyText = 'Aucun rappel en attente.',
}) {
  return (
    <div className="rounded-xl bg-card text-card-foreground shadow-sm overflow-hidden">
      <div className="w-full" data-slot="reminder-table">
        <Table className="table-fixed">
          <TableHeader>
            <TableRow className="bg-background hover:bg-background">
              <TableHead className="w-[4%] text-center">N°</TableHead>
              <TableHead className="w-[24%]">
                <SortHeader column="name" currentSortBy={sortBy} sortOrder={sortOrder} onSort={onSort}>
                  Prospect
                </SortHeader>
              </TableHead>
              <TableHead className="w-[13%]">Répondants</TableHead>
              <TableHead className="w-[10%]">N° de licence</TableHead>
              <TableHead className="w-[13%]">Rappel</TableHead>
              <TableHead className="w-[14%]">Statut</TableHead>
              <TableHead className="w-[22%]" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {reminders.map((r, i) => {
              const viewable = canView(r)

              return (
                <TableRow
                  key={r.id}
                  className={cn('cursor-pointer hover:bg-muted/50 transition-colors', !viewable && 'opacity-70')}
                  onClick={() => viewable && onView(r)}
                >
                  <TableCell className="text-center text-muted-foreground tabular-nums">
                    {startIndex + i + 1}
                  </TableCell>
                  <TableCell>
                    <div
                      className="flex w-full min-w-0 items-center gap-2.5"
                      title={[r.enterprise_name, r.client_name].filter(Boolean).join(' — ')}
                    >
                      <UserAvatar user={{ name: r.client_name, email: r.client_email }} size="sm" />
                      <div className="min-w-0 flex-1">
                        <p className="truncate">{r.enterprise_name || r.client_name || '—'}</p>
                        <small className="text-muted-foreground">{r.client_email || '—'}</small>
                      </div>
                    </div>
                  </TableCell>
                  <TableCell className="text-muted-foreground">
                    <div className="truncate" title={respondentsText({ respondents: r.respondents }) ?? undefined}>
                      {respondentsText({ respondents: r.respondents }) ?? '—'}
                    </div>
                  </TableCell>
                  <TableCell className="text-muted-foreground tabular-nums">
                    <div className="truncate" title={r.licence_number ?? undefined}>
                      {r.licence_number ?? '—'}
                    </div>
                  </TableCell>
                  <TableCell className="whitespace-normal">
                    <div className="flex flex-col gap-0.5">
                      <span
                        className={cn(
                          'inline-flex items-center gap-1.5 text-xs font-semibold',
                          r.is_due ? 'text-destructive' : 'text-muted-foreground'
                        )}
                      >
                        <Clock className="h-3.5 w-3.5 shrink-0" />
                        {formatRecallAt(r.recall_at)}
                      </span>
                      <span className="text-[11px] text-muted-foreground">{formatRecallFull(r.recall_at)}</span>
                    </div>
                  </TableCell>
                  <TableCell className="whitespace-normal overflow-hidden">
                    {/* Une seule valeur : statut client si blacklisté /
                        disponible, sinon réservation courante (§9). */}
                    <ProspectStatus
                      status={r.client_status}
                      displayStatus={r.display_status}
                      reservationStatus={r.reservation_status}
                      isBlacklisted={r.is_blacklisted}
                      returnedAt={r.returned_at}
                      className="w-full max-w-full"
                    />
                  </TableCell>
                  <TableCell className="text-right">
                    {viewable ? (
                      <div className="flex justify-end">
                        <button
                          onClick={(e) => { e.stopPropagation(); onView(r) }}
                          className="p-1.5 rounded-lg hover:bg-muted transition-colors"
                          aria-label="Voir l'historique"
                          title="Voir l'historique du client"
                        >
                          <Eye className="h-4 w-4 text-muted-foreground" />
                        </button>
                      </div>
                    ) : (
                      <div className="flex justify-end">
                        <Badge variant="outline" className="text-muted-foreground" title="Rappel déjà traité : nouveau suivi, statut changé ou rappel terminé.">
                          Obsolète
                        </Badge>
                      </div>
                    )}
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
        {reminders.length === 0 && (
          <div className="h-24 flex items-center justify-center text-muted-foreground">{emptyText}</div>
        )}
      </div>
    </div>
  )
}
