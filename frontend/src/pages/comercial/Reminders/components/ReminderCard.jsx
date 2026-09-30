import { Eye, Clock } from 'lucide-react'
import ProspectStatus from '@/pages/shared/components/ProspectStatus/index.jsx'
import UserAvatar from '@/pages/shared/components/UserAvatar/index.jsx'
import Badge from '@/components/ui/badge.jsx'
import { respondentsText, categoriesText } from '@/pages/comercial/ProspectList/components/prospectFormat'
import { cn } from '@/lib/utils.js'

/**
 * Carte (mobile) d'un rappel — même trame que `ProspectCard`, avec l'échéance.
 * **Aucune action directe** : seul l'œil « Voir » ouvre l'historique du client
 * (la page est consultative). Carte obsolète (`canView()` faux) : l'œil est
 * remplacé par la pastille « Obsolète ».
 */
export default function ReminderCard({ reminder: r, num, onView, canView, formatRecallAt, formatRecallFull }) {
  const viewable = canView(r)

  return (
    <div
      className="relative flex flex-col rounded-xl border border-border bg-card text-card-foreground p-5 shadow-sm transition-all hover:shadow-md cursor-pointer"
      onClick={() => viewable && onView(r)}
    >
      {viewable ? (
        <div className="absolute top-3 right-3">
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
        <div className="absolute top-3 right-3">
          <Badge variant="outline" className="text-muted-foreground">
            Obsolète
          </Badge>
        </div>
      )}

      <div className="flex items-center gap-3 mb-4">
        <UserAvatar user={{ name: r.client_name, email: r.client_email }} size="md" />
        <div className="min-w-0 flex-1">
          {num != null && <p className="text-[11px] text-muted-foreground">N° {num}</p>}
          <p className="text-sm font-semibold truncate">{r.client_name}</p>
          <p className="text-xs text-muted-foreground truncate">{r.enterprise_name ?? '—'}</p>
        </div>
      </div>

      <div className="space-y-2 text-sm flex-1">
        <div>
          <span className="text-muted-foreground text-xs">E-mail</span>
          <p className="truncate">{r.client_email ?? '—'}</p>
        </div>
        <div>
          <span className="text-muted-foreground text-xs">Municipalité</span>
          <p className="truncate">{r.client_municipality ?? '—'}</p>
        </div>
        <div>
          <span className="text-muted-foreground text-xs">N° de licence</span>
          <p className="truncate">{r.licence_number ?? '—'}</p>
        </div>
        <div>
          <span className="text-muted-foreground text-xs">NEQ</span>
          <p className="truncate">{r.neq ?? '—'}</p>
        </div>
        <div>
          <span className="text-muted-foreground text-xs">Catégorie</span>
          <p className="truncate">{categoriesText({ categories: r.categories }) ?? '—'}</p>
        </div>
        <div>
          <span className="text-muted-foreground text-xs">Répondants</span>
          <p className="truncate">{respondentsText({ respondents: r.respondents }) ?? '—'}</p>
        </div>
      </div>

      {/* Échéance du rappel */}
      <div className="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
        <span
          className={cn(
            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold',
            r.is_due ? 'bg-destructive/10 text-destructive' : 'bg-muted text-muted-foreground'
          )}
        >
          <Clock className="h-3.5 w-3.5" />
          {formatRecallAt(r.recall_at)}
        </span>
        <span className="text-xs text-muted-foreground">{formatRecallFull(r.recall_at)}</span>
      </div>

      {/* Une seule valeur : statut client si blacklisté/disponible, sinon
          statut de la réservation courante (docs/RULES.md §9). */}
      <div className="flex flex-wrap items-center gap-2 mt-4 pt-3 border-t border-border">
        <ProspectStatus
          status={r.client_status}
          displayStatus={r.display_status}
          reservationStatus={r.reservation_status}
          isBlacklisted={r.is_blacklisted}
          returnedAt={r.returned_at}
        />
      </div>
    </div>
  )
}
