import { Eye, Ban, Loader2, Unlock } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import SortHeader from '@/pages/shared/components/SortHeader/index.jsx'
import UserAvatar from '@/pages/shared/components/UserAvatar/index.jsx'
import ProspectStatus from '@/pages/shared/components/ProspectStatus/index.jsx'
import { respondentsText } from './prospectFormat'

/**
 * Tableau de la liste de prospects — **sans défilement horizontal**.
 *
 * Le tableau occupe 100 % de la largeur disponible et les largeurs de colonnes
 * sont exprimées en % (`table-fixed`) : toutes les colonnes restent donc
 * visibles, quelle que soit la taille de l'écran. Les textes plus longs que
 * leur cellule sont tronqués (`truncate`) et restent accessibles au survol via
 * l'attribut `title` — c'est ce qui évite de réintroduire un `overflow-x`.
 *
 * @param {boolean} [rowClickable=true]  `false` : la ligne ne navigue plus
 *   (Grande liste admin, `/clients-historique`) — l'accès au détail reste
 *   possible via le bouton « Voir ».
 * @param {(c: object) => void} [onToggleBlacklist]  bouton de **bascule**
 *   liste noire / débloquer en fin de ligne : action directe, **sans modale
 *   de confirmation** (l'état du client décide de l'icône et de l'action).
 * @param {number|null} [blacklistId]  id en cours de bascule (spinner).
 */
export default function ProspectTable({
  clients,
  startIndex = 0,
  sortBy,
  sortOrder,
  onSort,
  onViewDetail,
  showViewButton = true,
  rowClickable = true,
  onToggleBlacklist,
  blacklistId = null,
}) {
  // La colonne « Statut » récupère la place du bouton « Voir » quand il est
  // masqué, pour que la somme des largeurs reste à 100 %.
  const hasActionCell = showViewButton || Boolean(onToggleBlacklist)
  const statusWidth = hasActionCell ? 'w-[17%]' : 'w-[22%]'
  // La colonne d'action s'élargit quand elle porte les deux boutons
  // (« Voir » + « liste noire / débloquer ») : la colonne « Prospect » cède
  // la place, le total reste à 100 % (5 + 27 + 25 + 18 + 17 + 8).
  const actionWidth = onToggleBlacklist ? 'w-[8%]' : 'w-[5%]'
  const nameWidth = onToggleBlacklist ? 'w-[27%]' : 'w-[30%]'
  const clickable = rowClickable && typeof onViewDetail === 'function'
  // Un seul vocabulaire pour « ce client est en liste noire » (§2).
  const isBlocked = (c) => Boolean(c.is_blacklisted) || c.status === 'BLACKLISTED'

  return (
    <div className="rounded-xl bg-card text-card-foreground shadow-sm overflow-hidden">
      <div className="w-full" data-slot="prospect-table">
        <Table className="table-fixed">
          <TableHeader>
            <TableRow className="bg-background hover:bg-background">
              <TableHead className="w-[5%] text-center">N°</TableHead>
              <TableHead className={nameWidth}>
                <SortHeader column="name" currentSortBy={sortBy} sortOrder={sortOrder} onSort={onSort}>
                Prospect
                </SortHeader>
              </TableHead>
              <TableHead className="w-[25%]">Répondants</TableHead>
              <TableHead className="w-[18%]">N° de licence</TableHead>
              <TableHead className={statusWidth}>Statut</TableHead>
              {hasActionCell && <TableHead className={actionWidth} />}
            </TableRow>
          </TableHeader>
          <TableBody>
            {clients.map((c, i) => (
              <TableRow
                key={c.id}
                className={
                  clickable
                    ? 'cursor-pointer hover:bg-muted/50 transition-colors'
                    : 'transition-colors'
                }
                onClick={clickable ? () => onViewDetail?.(c) : undefined}
              >
                <TableCell className="text-center text-muted-foreground tabular-nums">
                  {startIndex + i + 1}
                </TableCell>
                <TableCell>
                  <div
                    className="flex w-full min-w-0 items-center gap-2.5"
                    title={[c.enterprise_name, c.name].filter(Boolean).join(' — ')}
                  >
                    <UserAvatar user={c} size="sm" />
                    <div className="min-w-0 flex-1">
                      <p className="truncate">{c.enterprise_name || c.name || '—'}</p>
                      <small className="text-muted-foreground">
                        {c.email  || '—'}
                      </small>
                    </div>
                  </div>
                </TableCell>
                <TableCell className="text-muted-foreground">
                  <div className="truncate" title={respondentsText(c) ?? undefined}>
                    {respondentsText(c) ?? '—'}
                  </div>
                </TableCell>
                <TableCell className="text-muted-foreground tabular-nums">
                  <div className="truncate" title={c.licence_number ?? undefined}>
                    {c.licence_number ?? '—'}
                  </div>
                </TableCell>
                {/* Une seule valeur : statut client si blacklisté/disponible,
                    sinon statut de la réservation courante (§9). */}
                <TableCell className="whitespace-normal overflow-hidden">
                  <ProspectStatus
                    status={c.status}
                    displayStatus={c.display_status}
                    reservationStatus={c.reservation_status}
                    isBlacklisted={c.is_blacklisted}
                    returnedAt={c.returned_at}
                    className="w-full max-w-full"
                  />
                </TableCell>
                {hasActionCell && (
                  <TableCell className="text-right whitespace-nowrap">
                    {showViewButton && (
                      <button
                        onClick={(e) => { e.stopPropagation(); onViewDetail?.(c) }}
                        className="p-1.5 rounded-lg hover:bg-muted transition-colors"
                        aria-label="Voir détail"
                      >
                        <Eye className="h-4 w-4 text-muted-foreground" />
                      </button>
                    )}
                    {/* Bascule liste noire / débloquer — action directe,
                        **sans modale de confirmation** : le bouton enchaîne
                        `commercials/clients/{id}/blacklist` (Ban, rouge) et
                        `liste-noire/{id}/debloquer` (Unlock, vert) selon
                        l'état du client. */}
                    {onToggleBlacklist && (
                      <button
                        onClick={(e) => { e.stopPropagation(); onToggleBlacklist(c) }}
                        disabled={blacklistId === c.id}
                        className={`p-1.5 rounded-lg transition-colors disabled:opacity-60 ${
                          isBlocked(c) ? 'hover:bg-emerald-500/10' : 'hover:bg-destructive/10'
                        }`}
                        aria-label={isBlocked(c) ? 'Débloquer le client' : 'Mettre en liste noire'}
                        title={isBlocked(c) ? 'Débloquer' : 'Mettre en liste noire'}
                      >
                        {blacklistId === c.id ? (
                          <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
                        ) : isBlocked(c) ? (
                          <Unlock className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                        ) : (
                          <Ban className="h-4 w-4 text-destructive" />
                        )}
                      </button>
                    )}
                  </TableCell>
                )}
              </TableRow>
            ))}
          </TableBody>
        </Table>
        {clients.length === 0 && (
          <div className="h-24 flex items-center justify-center text-muted-foreground">Aucun prospect trouvé.</div>
        )}
      </div>
    </div>
  )
}
