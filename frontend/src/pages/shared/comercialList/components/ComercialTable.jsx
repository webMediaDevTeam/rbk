import { Link } from 'react-router-dom'
import { Eye, Pencil, Power, Trash2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import StatusBadge from './StatusBadge'
import RowMenu from '@/pages/shared/components/RowMenu.jsx'
import SortHeader from '@/pages/shared/components/SortHeader.jsx'
import UserAvatar from '@/pages/shared/components/UserAvatar.jsx'
import { useComercialTable } from './useComercialTable.js'

export default function ComercialTable(props) {
  const { rows, sortBy, sortOrder, onSort, canDelete } = useComercialTable(props)

  return (
    <div className="rounded-xl bg-card text-card-foreground shadow-sm overflow-hidden">
      <Table>
        <TableHeader>
          <TableRow className="bg-background hover:bg-background">
            <TableHead>
              <SortHeader column="name" currentSortBy={sortBy} sortOrder={sortOrder} onSort={onSort}>Commercial</SortHeader>
            </TableHead>
            <TableHead>
              <SortHeader column="email" currentSortBy={sortBy} sortOrder={sortOrder} onSort={onSort}>Adresse e-mail</SortHeader>
            </TableHead>
            <TableHead>Téléphone</TableHead>
            <TableHead>Entreprise</TableHead>
            <TableHead>
              <SortHeader column="status" currentSortBy={sortBy} sortOrder={sortOrder} onSort={onSort}>Statut</SortHeader>
            </TableHead>
            <TableHead className="w-10" />
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((row) => (
            <TableRow key={row.key}>
              <TableCell>
                <div className="flex items-center gap-2.5">
                  <UserAvatar user={row.c} size="sm" onEdit={row.handleAvatarClick} />
                  <span className="font-medium">{row.name}</span>
                </div>
              </TableCell>
              <TableCell className="text-muted-foreground">{row.c.email}</TableCell>
              <TableCell className="text-muted-foreground">{row.phone}</TableCell>
              <TableCell className="text-muted-foreground">{row.enterpriseName}</TableCell>
              <TableCell><StatusBadge status={row.c.status} /></TableCell>
              <TableCell className="text-right">
                <RowMenu>
                  {(closeMenu) => (
                    <>
                      <Link to={`/comercialDetail/${row.c.id}`} className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-muted transition-colors"><Eye className="h-3.5 w-3.5" /> Voir</Link>
                      <button
                        onClick={() => row.handleEdit(closeMenu)}
                        className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-muted transition-colors"
                      >
                        <Pencil className="h-3.5 w-3.5" /> Modifier
                      </button>
                      <button
                        onClick={() => row.handleToggleStatus(closeMenu)}
                        className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-muted transition-colors"
                      >
                        <Power className="h-3.5 w-3.5" /> {row.toggleLabel}
                      </button>
                      {canDelete && (
                        <button
                          onClick={() => row.handleDelete(closeMenu)}
                          className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-destructive/10 text-destructive transition-colors"
                        >
                          <Trash2 className="h-3.5 w-3.5" /> Supprimer
                        </button>
                      )}
                    </>
                  )}
                </RowMenu>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
      {rows.length === 0 && (
        <div className="h-24 flex items-center justify-center text-muted-foreground">Aucun résultat.</div>
      )}
    </div>
  )
}
