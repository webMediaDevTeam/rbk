import { Link } from 'react-router-dom'
import { AlertTriangle, Eye, Pencil, Power, Trash2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import StatusBadge from './StatusBadge'
import RowMenu from '@/pages/shared/components/RowMenu/index.jsx'
import SortHeader from '@/pages/shared/components/SortHeader/index.jsx'
import UserAvatar from '@/pages/shared/components/UserAvatar/index.jsx'
import { useComercialTable } from './useComercialTable.js'

export default function ComercialTable(props) {
  const { rows, sortBy, sortOrder, onSort, canDelete } = useComercialTable(props)

  return (
    <div className="rounded-xl bg-card text-card-foreground shadow-sm overflow-hidden">
      <Table>
        <TableHeader>
          <TableRow className="bg-background hover:bg-background">
            <TableHead>
              <SortHeader column="name" currentSortBy={sortBy} sortOrder={sortOrder} onSort={onSort}>Employé</SortHeader>
            </TableHead>
            <TableHead>
              <SortHeader column="email" currentSortBy={sortBy} sortOrder={sortOrder} onSort={onSort}>Adresse e-mail</SortHeader>
            </TableHead>
            <TableHead>Téléphone</TableHead>
            <TableHead>N° RingCentral</TableHead>
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
              <TableCell className="text-muted-foreground">
                {row.rcNumber ? (
                  <span className="inline-flex items-center gap-1.5">
                    <span className="tabular-nums">{row.rcNumber}</span>
                    {row.rcDuplicate && (
                      <span
                        className="inline-flex items-center gap-1 rounded-md bg-amber-500/15 px-1.5 py-0.5 text-xs font-medium text-amber-600"
                        title="Ce numéro RingCentral est partagé avec un autre employé — corrigez la source d'appel"
                      >
                        <AlertTriangle className="h-3.5 w-3.5" /> Doublon
                      </span>
                    )}
                  </span>
                ) : (
                  '—'
                )}
              </TableCell>
              <TableCell className="text-muted-foreground">{row.enterpriseName}</TableCell>
              <TableCell><StatusBadge status={row.c.status} /></TableCell>
              <TableCell className="text-right">
                <div className="flex items-center justify-end gap-1">
                  <Link
                    to={`/comercialDetail/${row.c.id}`}
                    className="inline-flex items-center justify-center h-8 w-8 rounded-md hover:bg-muted text-muted-foreground transition-colors"
                    title="Voir"
                    aria-label="Voir"
                  >
                    <Eye className="h-4 w-4" />
                  </Link>
                  <RowMenu>
                    {(closeMenu) => (
                      <>
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
                </div>
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
