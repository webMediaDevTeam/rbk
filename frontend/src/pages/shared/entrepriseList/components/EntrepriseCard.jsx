import { Pencil, Power, Trash2 } from 'lucide-react'
import StatusBadge from './StatusBadge'
import UserAvatar from '@/pages/shared/components/UserAvatar/index.jsx'
import RowMenu from '@/pages/shared/components/RowMenu/index.jsx'
import { useEntrepriseCard } from './useEntrepriseCard.js'

export default function EntrepriseCard(props) {
  const {
    entreprise,
    name,
    email,
    phone,
    taxNumber,
    toggleLabel,
    canDelete,
    handleAvatarClick,
    handleEdit,
    handleToggleStatus,
    handleDelete,
  } = useEntrepriseCard(props)

  return (
    <div className="relative flex flex-col rounded-xl border border-border bg-card text-card-foreground p-5 shadow-sm transition-all hover:shadow-md">
      <div className="absolute top-3 right-3">
        <RowMenu>
          {(closeMenu) => (
            <>
              <button
                onClick={() => handleEdit(closeMenu)}
                className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-muted transition-colors"
              >
                <Pencil className="h-3.5 w-3.5" /> Modifier
              </button>
              <button
                onClick={() => handleToggleStatus(closeMenu)}
                className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-muted transition-colors"
              >
                <Power className="h-3.5 w-3.5" /> {toggleLabel}
              </button>
              {canDelete && (
                <button
                  onClick={() => handleDelete(closeMenu)}
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
        <UserAvatar user={entreprise} size="md" onEdit={handleAvatarClick} />
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold truncate">{name}</p>
          <p className="text-xs text-muted-foreground truncate">{email}</p>
        </div>
      </div>

      <div className="space-y-2 text-sm flex-1">
        <div>
          <span className="text-muted-foreground text-xs">Téléphone</span>
          <p className="truncate">{phone}</p>
        </div>
        <div>
          <span className="text-muted-foreground text-xs">Numéro fiscal</span>
          <p>{taxNumber}</p>
        </div>
      </div>

      <div className="flex items-center justify-between mt-4 pt-3 border-t border-border">
        <StatusBadge status={entreprise.status} />
        <span className="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
          Entreprise
        </span>
      </div>
    </div>
  )
}
