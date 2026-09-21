import { Pencil, Power, Trash2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import StatusBadge from './StatusBadge'
import RowMenu from '@/pages/shared/components/RowMenu/index.jsx'
import SortHeader from '@/pages/shared/components/SortHeader/index.jsx'
import UserAvatar from '@/pages/shared/components/UserAvatar/index.jsx'

export default function AdminTable({ admins, sortBy, sortOrder, onSort, onToggleStatus, onDelete, onAvatarClick, onEdit }) {
  return (
    <div className="rounded-xl bg-card text-card-foreground shadow-sm overflow-hidden">
      <Table>
        <TableHeader>
          <TableRow className="bg-background hover:bg-background">
            <TableHead>
              <SortHeader column="email" currentSortBy={sortBy} sortOrder={sortOrder} onSort={onSort}>Admin</SortHeader>
            </TableHead>
            <TableHead>Téléphone</TableHead>
            <TableHead>
              <SortHeader column="status" currentSortBy={sortBy} sortOrder={sortOrder} onSort={onSort}>Statut</SortHeader>
            </TableHead>
            <TableHead>
              <SortHeader column="created_at" currentSortBy={sortBy} sortOrder={sortOrder} onSort={onSort}>Créé le</SortHeader>
            </TableHead>
            <TableHead className="w-10" />
          </TableRow>
        </TableHeader>
        <TableBody>
          {admins.map((admin) => (
            <TableRow key={admin.id}>
              <TableCell>
                <div className="flex items-center gap-2.5">
                  <UserAvatar user={admin} size="sm" onEdit={() => onAvatarClick?.(admin)} />
                  <div className="flex flex-col">
                    <span className="font-medium">{admin.first_name || admin.last_name ? `${admin.first_name ?? ''} ${admin.last_name ?? ''}`.trim() : admin.email}</span>
                    {admin.first_name || admin.last_name ? <span className="text-xs text-muted-foreground">{admin.email}</span> : null}
                  </div>
                </div>
              </TableCell>
              <TableCell className="text-muted-foreground">{admin.phone ?? '—'}</TableCell>
              <TableCell><StatusBadge status={admin.status} /></TableCell>
              <TableCell className="text-muted-foreground">
                {new Date(admin.created_at).toLocaleDateString('fr-CA')}
              </TableCell>
              <TableCell className="text-right">
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
                        onClick={() => { onToggleStatus(admin.id, admin.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE'); closeMenu() }}
                        className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-muted transition-colors"
                      >
                        <Power className="h-3.5 w-3.5" /> {admin.status === 'ACTIVE' ? 'Désactiver' : 'Activer'}
                      </button>
                      <button
                        onClick={() => { onDelete(admin.id); closeMenu() }}
                        className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-destructive/10 text-destructive transition-colors"
                      >
                        <Trash2 className="h-3.5 w-3.5" /> Supprimer
                      </button>
                    </>
                  )}
                </RowMenu>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
      {admins.length === 0 && (
        <div className="h-24 flex items-center justify-center text-muted-foreground">Aucun résultat.</div>
      )}
    </div>
  )
}