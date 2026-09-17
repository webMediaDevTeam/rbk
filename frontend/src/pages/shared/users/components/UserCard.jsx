import { MoreHorizontal } from 'lucide-react'
import { Avatar, AvatarFallback } from '../../../../components/ui/avatar'
import StatusBadge from './StatusBadge'
import RoleBadge from './RoleBadge'

export default function UserCard({ user, isSelected, onSelect }) {
  const initials = user.name
    .split(' ')
    .map((n) => n[0])
    .join('')
    .slice(0, 2)
    .toUpperCase()

  return (
    <div
      className={`flex flex-col rounded-xl border bg-card text-card-foreground p-5 shadow-sm transition-all hover:shadow-md ${
        isSelected ? 'border-primary ring-1 ring-primary' : 'border-border'
      }`}
    >
      {/* Avatar + name + menu on same line */}
      <div className="flex items-center gap-3 mb-4">
        <input
          type="checkbox"
          checked={isSelected}
          onChange={() => onSelect(user.id)}
          className="h-4 w-4 rounded border-border accent-primary flex-shrink-0"
        />
        <Avatar className="h-11 w-11">
          <AvatarFallback>{initials}</AvatarFallback>
        </Avatar>
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold truncate">{user.name}</p>
          <p className="text-xs text-muted-foreground truncate">@{user.username}</p>
        </div>
        <button className="inline-flex items-center justify-center h-8 w-8 rounded-lg hover:bg-muted text-muted-foreground flex-shrink-0" aria-label="More actions">
          <MoreHorizontal className="h-4 w-4" />
        </button>
      </div>

      {/* Info */}
      <div className="space-y-2 text-sm flex-1">
        <div>
          <span className="text-muted-foreground text-xs">Email</span>
          <p className="truncate">{user.email}</p>
        </div>
        <div>
          <span className="text-muted-foreground text-xs">Phone</span>
          <p>{user.phone}</p>
        </div>
      </div>

      {/* Badges */}
      <div className="flex items-center justify-between mt-4 pt-3 border-t border-border">
        <StatusBadge status={user.status} />
        <RoleBadge role={user.role} />
      </div>
    </div>
  )
}