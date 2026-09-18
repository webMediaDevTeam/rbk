import { Eye } from 'lucide-react'
import ProspectStatusBadge from './ProspectStatusBadge'
import UserAvatar from '@/pages/shared/components/UserAvatar.jsx'

export default function ProspectCard({ client, onViewDetail }) {
  return (
    <div
      className="relative flex flex-col rounded-xl border border-border bg-card text-card-foreground p-5 shadow-sm transition-all hover:shadow-md cursor-pointer"
      onClick={() => onViewDetail?.(client)}
    >
      <div className="absolute top-3 right-3">
        <button
          onClick={(e) => { e.stopPropagation(); onViewDetail?.(client) }}
          className="p-1.5 rounded-lg hover:bg-muted transition-colors"
          aria-label="Voir détail"
        >
          <Eye className="h-4 w-4 text-muted-foreground" />
        </button>
      </div>

      <div className="flex items-center gap-3 mb-4">
        <UserAvatar user={client} size="md" />
        <div className="min-w-0 flex-1">
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
      </div>

      <div className="flex items-center justify-between mt-4 pt-3 border-t border-border">
        <ProspectStatusBadge status={client.status} isBlacklisted={client.is_blacklisted} />
        <span className="text-xs text-muted-foreground">
          {client.licence_end_date ? `Licence: ${new Date(client.licence_end_date).toLocaleDateString('fr-FR')}` : '—'}
        </span>
      </div>
    </div>
  )
}