import { Eye, Ban, Loader2, Unlock } from 'lucide-react'
import ProspectStatus from '@/pages/shared/components/ProspectStatus/index.jsx'
import UserAvatar from '@/pages/shared/components/UserAvatar/index.jsx'
import { respondentsText, categoriesText } from './prospectFormat'

/**
 * Carte (mobile) d'un prospect.
 *
 * @param {boolean} [rowClickable=true]  `false` : la carte ne navigue plus
 *   (Grande liste admin, `/clients-historique`).
 * @param {(client: object) => void} [onToggleBlacklist]  bascule **liste
 *   noire / débloquer**, action directe **sans modale de confirmation**.
 * @param {number|null} [blacklistId]  id en cours de bascule (spinner).
 */
export default function ProspectCard({
  client,
  num,
  onViewDetail,
  showViewButton = true,
  rowClickable = true,
  onToggleBlacklist,
  blacklistId = null,
}) {
  const clickable = rowClickable && typeof onViewDetail === 'function'
  const blocked = Boolean(client.is_blacklisted) || client.status === 'BLACKLISTED'
  const busy = blacklistId === client.id

  return (
    <div
      className={`relative flex flex-col rounded-xl border border-border bg-card text-card-foreground p-5 shadow-sm transition-all hover:shadow-md${clickable ? ' cursor-pointer' : ''}`}
      onClick={clickable ? () => onViewDetail?.(client) : undefined}
    >
      {(showViewButton || onToggleBlacklist) && (
        <div className="absolute top-3 right-3 flex items-center gap-1">
          {showViewButton && (
            <button
              onClick={(e) => { e.stopPropagation(); onViewDetail?.(client) }}
              className="p-1.5 rounded-lg hover:bg-muted transition-colors"
              aria-label="Voir détail"
            >
              <Eye className="h-4 w-4 text-muted-foreground" />
            </button>
          )}
          {onToggleBlacklist && (
            <button
              onClick={(e) => { e.stopPropagation(); onToggleBlacklist(client) }}
              disabled={busy}
              className={`p-1.5 rounded-lg transition-colors disabled:opacity-60 ${
                blocked ? 'hover:bg-emerald-500/10' : 'hover:bg-destructive/10'
              }`}
              aria-label={blocked ? 'Débloquer le client' : 'Mettre en liste noire'}
              title={blocked ? 'Débloquer' : 'Mettre en liste noire'}
            >
              {busy ? (
                <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
              ) : blocked ? (
                <Unlock className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
              ) : (
                <Ban className="h-4 w-4 text-destructive" />
              )}
            </button>
          )}
        </div>
      )}

      <div className="flex items-center gap-3 mb-4">
        <UserAvatar user={client} size="md" />
        <div className="min-w-0 flex-1">
          {num != null && <p className="text-[11px] text-muted-foreground">N° {num}</p>}
          <p className="text-sm font-semibold truncate">{client.name}</p>
          <p className="text-xs text-muted-foreground truncate">{client.enterprise_name ?? '—'}</p>
        </div>
      </div>

      <div className="space-y-2 text-sm flex-1">
        <div>
          <span className="text-muted-foreground text-xs">E-mail</span>
          <p className="truncate">{client.email ?? '—'}</p>
        </div>
       
        <div>
          <span className="text-muted-foreground text-xs">Municipalité</span>
          <p className="truncate">{client.municipality ?? '—'}</p>
        </div>
        <div>
          <span className="text-muted-foreground text-xs">N° de licence</span>
          <p className="truncate">{client.licence_number ?? '—'}</p>
        </div>
        <div>
          <span className="text-muted-foreground text-xs">NEQ</span>
          <p className="truncate">{client.neq ?? '—'}</p>
        </div>
        <div>
          <span className="text-muted-foreground text-xs">Catégorie</span>
          <p className="truncate">{categoriesText(client) ?? '—'}</p>
        </div>
        <div>
          <span className="text-muted-foreground text-xs">Répondants</span>
          <p className="truncate">{respondentsText(client) ?? '—'}</p>
        </div>
      </div>

      <div className="flex items-center justify-between mt-4 pt-3 border-t border-border">
        <ProspectStatus
          status={client.status}
          displayStatus={client.display_status}
          reservationStatus={client.reservation_status}
          isBlacklisted={client.is_blacklisted}
          returnedAt={client.returned_at}
        />
        <span className="text-xs text-muted-foreground">
          {client.licence_end_date ? `Licence: ${new Date(client.licence_end_date).toLocaleDateString('fr-FR')}` : '—'}
        </span>
      </div>
    </div>
  )
}