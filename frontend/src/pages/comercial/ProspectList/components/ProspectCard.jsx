import { Eye } from 'lucide-react'
import ClientStatus from '@/pages/shared/components/ClientStatus/index.jsx'
import UserAvatar from '@/pages/shared/components/UserAvatar/index.jsx'
import { respondentsText, categoriesText } from './prospectFormat'

export default function ProspectCard({ client, num, onViewDetail, showViewButton = true }) {
  return (
    <div
      className="relative flex flex-col rounded-xl border border-border bg-card text-card-foreground p-5 shadow-sm transition-all hover:shadow-md cursor-pointer"
      onClick={() => onViewDetail?.(client)}
    >
      {showViewButton && (
        <div className="absolute top-3 right-3">
          <button
            onClick={(e) => { e.stopPropagation(); onViewDetail?.(client) }}
            className="p-1.5 rounded-lg hover:bg-muted transition-colors"
            aria-label="Voir détail"
          >
            <Eye className="h-4 w-4 text-muted-foreground" />
          </button>
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
          <span className="text-muted-foreground text-xs">Téléphone</span>
          <p className="truncate">{client.phone ?? '—'}</p>
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
        <ClientStatus
          status={client.display_status ?? client.status}
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