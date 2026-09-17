import { Pencil, Power, Trash2 } from 'lucide-react'
import StatusBadge from './StatusBadge'
import UserAvatar from '@/pages/shared/components/UserAvatar.jsx'
import RowMenu from '@/pages/shared/components/RowMenu.jsx'

export default function ComercialCard({ comercial, onAvatarClick, onToggleStatus, onDelete, canDelete, onEdit }) {
  const profil = comercial.profil
  const name = profil ? `${profil.prenom} ${profil.nom}` : comercial.email

  return (
    <div className="relative flex flex-col rounded-xl border border-border bg-card text-card-foreground p-5 shadow-sm transition-all hover:shadow-md">
      <div className="absolute top-3 right-3">
        <RowMenu>
          {(closeMenu) => (
            <>
              <button
                onClick={() => { onEdit?.(comercial); closeMenu() }}
                className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-muted transition-colors"
              >
                <Pencil className="h-3.5 w-3.5" /> Modifier
              </button>
              <button
                onClick={() => { onToggleStatus?.(comercial.id, comercial.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE'); closeMenu() }}
                className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-muted transition-colors"
              >
                <Power className="h-3.5 w-3.5" /> {comercial.status === 'ACTIVE' ? 'Désactiver' : 'Activer'}
              </button>
              {canDelete && (
                <button
                  onClick={() => { onDelete?.(comercial.id); closeMenu() }}
                  className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-destructive/10 text-destructive transition-colors"
                >
                  <Trash2 className="h-3.5 w-3.5" /> Supprimer
                </button>
              )}
            </>
          )}
        </RowMenu>
      </div>

      <div className="flex items-center gap-3 mb-4">
        <UserAvatar user={comercial} size="md" onEdit={() => onAvatarClick?.(comercial)} />
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold truncate">{name}</p>
          <p className="text-xs text-muted-foreground truncate">{comercial.email}</p>
        </div>
      </div>

      <div className="space-y-2 text-sm flex-1">
        <div>
          <span className="text-muted-foreground text-xs">Téléphone</span>
          <p className="truncate">{profil?.telephone ?? '—'}</p>
        </div>
      </div>

      <div className="flex items-center justify-between mt-4 pt-3 border-t border-border">
        <StatusBadge status={comercial.status} />
        <span className="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
          Commercial
        </span>
      </div>
    </div>
  )
}