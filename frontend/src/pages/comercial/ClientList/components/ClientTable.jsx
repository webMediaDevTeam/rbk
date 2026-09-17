import { Eye } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import ClientStatusBadge from './ClientStatusBadge'
import SortHeader from '@/pages/shared/components/SortHeader.jsx'
import UserAvatar from '@/pages/shared/components/UserAvatar.jsx'
import RowMenu from '@/pages/shared/components/RowMenu.jsx'

export default function ClientTable({ clients, sortBy, sortOrder, onSort, onViewDetail }) {
  return (
    <div className="rounded-xl bg-card text-card-foreground shadow-sm overflow-hidden">
      <Table>
        <TableHeader>
          <TableRow className="bg-background hover:bg-background">
            <TableHead>
              <SortHeader column="name" currentSortBy={sortBy} sortOrder={sortOrder} onSort={onSort}>
                Client
              </SortHeader>
            </TableHead>
            <TableHead>
              <SortHeader column="email" currentSortBy={sortBy} sortOrder={sortOrder} onSort={onSort}>
                Email
              </SortHeader>
            </TableHead>
            <TableHead>Téléphone</TableHead>
            <TableHead>Municipalité</TableHead>
            <TableHead>
              <SortHeader column="status" currentSortBy={sortBy} sortOrder={sortOrder} onSort={onSort}>
                Statut
              </SortHeader>
            </TableHead>
            <TableHead>
              <SortHeader column="licence_end_date" currentSortBy={sortBy} sortOrder={sortOrder} onSort={onSort}>
                Fin licence
              </SortHeader>
            </TableHead>
            <TableHead className="w-10" />
          </TableRow>
        </TableHeader>
        <TableBody>
          {clients.map((c) => (
            <TableRow key={c.id}>
              <TableCell>
                <div className="flex items-center gap-2.5">
                  <UserAvatar user={c} size="sm" />
                  <div>
                    <span className="font-medium">{c.name}</span>
                    <p className="text-xs text-muted-foreground">{c.enterprise_name}</p>
                  </div>
                </div>
              </TableCell>
              <TableCell className="text-muted-foreground">{c.email ?? '—'}</TableCell>
              <TableCell className="text-muted-foreground">{c.phone ?? '—'}</TableCell>
              <TableCell className="text-muted-foreground">{c.municipality ?? '—'}</TableCell>
              <TableCell><ClientStatusBadge status={c.status} isBlacklisted={c.is_blacklisted} /></TableCell>
              <TableCell className="text-muted-foreground">{c.licence_end_date ? new Date(c.licence_end_date).toLocaleDateString('fr-FR') : '—'}</TableCell>
              <TableCell className="text-right">
                <RowMenu>
                  {(closeMenu) => (
                    <button
                      onClick={() => { onViewDetail?.(c); closeMenu() }}
                      className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-muted transition-colors"
                    >
                      <Eye className="h-3.5 w-3.5" /> Voir détail
                    </button>
                  )}
                </RowMenu>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
      {clients.length === 0 && (
        <div className="h-24 flex items-center justify-center text-muted-foreground">Aucun client trouvé.</div>
      )}
    </div>
  )
}
