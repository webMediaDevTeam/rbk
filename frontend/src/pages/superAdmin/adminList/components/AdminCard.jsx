import { Pencil, Power, ShieldCheck, Trash2 } from 'lucide-react'
import StatusBadge from './StatusBadge'
import UserAvatar from '@/pages/shared/components/UserAvatar/index.jsx'
import RowMenu from '@/pages/shared/components/RowMenu/index.jsx'

export default function AdminCard({ admin, onAvatarClick, onToggleStatus, onDelete, onEdit }) {
  return (
    <div className="relative flex flex-col rounded-xl border border-border bg-card text-card-foreground p-5 shadow-sm transition-all hover:shadow-md">
      <div className="absolute top-3 right-3">
        <RowMenu>
          {(closeMenu) => (
            <>
              <button
                onClick={() => { onEdit?.(admin); closeMenu() }}
                className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-muted transition-colors"
              >
                <Pencil className="h-3.5 w-3.5" /> Modifier
              </button>
              <button
                onClick={() => { onToggleStatus?.(admin.id, admin.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE'); closeMenu() }}
                className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-muted transition-colors"
              >
                <Power className="h-3.5 w-3.5" /> {admin.status === 'ACTIVE' ? 'Désactiver' : 'Activer'}
              </button>
              <button
                onClick={() => { onDelete?.(admin.id); closeMenu() }}
                className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-destructive/10 text-destructive transition-colors"
              >
                <Trash2 className="h-3.5 w-3.5" /> Supprimer
              </button>
            </>
          )}
        </RowMenu>
      </div>

      <div className="flex items-center gap-3 mb-4">
        <UserAvatar user={admin} size="md" onEdit={() => onAvatarClick?.(admin)} />
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold truncate">{admin.first_name || admin.last_name ? `${admin.first_name ?? ''} ${admin.last_name ?? ''}`.trim() : admin.email}</p>
          {admin.first_name || admin.last_name ? <p className="text-xs text-muted-foreground truncate">{admin.email}</p> : null}
          <p className="text-xs text-muted-foreground flex items-center gap-1">
            <ShieldCheck className="h-3 w-3" /> Admin
          </p>
        </div>
      </div>

      <div className="space-y-2 text-sm flex-1">
        {admin.phone && (
          <div>
            <span className="text-muted-foreground text-xs">Téléphone</span>
            <p>{admin.phone}</p>
          </div>
        )}
        <div>
          <span className="text-muted-foreground text-xs">Créé le</span>
          <p>{new Date(admin.created_at).toLocaleDateString('fr-CA')}</p>
        </div>
      </div>

      <div className="flex items-center justify-between mt-4 pt-3 border-t border-border">
        <StatusBadge status={admin.status} />
        <span className="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
          <ShieldCheck className="h-3.5 w-3.5" /> Admin
        </span>
      </div>
    </div>
  )
}