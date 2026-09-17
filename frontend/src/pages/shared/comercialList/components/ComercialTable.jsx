import { Pencil, Power, Trash2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../../../components/ui/table'
import StatusBadge from './StatusBadge'
import RowMenu from '../../components/RowMenu.jsx'
import SortHeader from '../../components/SortHeader.jsx'
import UserAvatar from '../../components/UserAvatar.jsx'

export default function ComercialTable({ commerciaux, sortBy, sortOrder, onSort, onToggleStatus, onDelete, canDelete, onAvatarClick, onEdit }) {
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
            <TableHead>
              <SortHeader column="status" currentSortBy={sortBy} sortOrder={sortOrder} onSort={onSort}>Statut</SortHeader>
            </TableHead>
            <TableHead className="w-10" />
          </TableRow>
        </TableHeader>
        <TableBody>
          {commerciaux.map((c) => {
            const profil = c.profil
            const name = profil ? `${profil.prenom} ${profil.nom}` : '—'
            return (
              <TableRow key={c.id}>
                <TableCell>
                  <div className="flex items-center gap-2.5">
                    <UserAvatar user={c} size="sm" onEdit={() => onAvatarClick?.(c)} />
                    <span className="font-medium">{name}</span>
                  </div>
                </TableCell>
                <TableCell className="text-muted-foreground">{c.email}</TableCell>
                <TableCell className="text-muted-foreground">{profil?.telephone ?? '—'}</TableCell>
                <TableCell><StatusBadge status={c.status} /></TableCell>
                <TableCell className="text-right">
                  <RowMenu>
                    {(closeMenu) => (
                      <>
                        <button
                          onClick={() => { onEdit?.(c); closeMenu() }}
                          className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-muted transition-colors"
                        >
                          <Pencil className="h-3.5 w-3.5" /> Modifier
                        </button>
                        <button
                          onClick={() => { onToggleStatus(c.id, c.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE'); closeMenu() }}
                          className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-muted transition-colors"
                        >
                          <Power className="h-3.5 w-3.5" /> {c.status === 'ACTIVE' ? 'Désactiver' : 'Activer'}
                        </button>
                        {canDelete && (
                          <button
                            onClick={() => { onDelete(c.id); closeMenu() }}
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
            )
          })}
        </TableBody>
      </Table>
      {commerciaux.length === 0 && (
        <div className="h-24 flex items-center justify-center text-muted-foreground">Aucun résultat.</div>
      )}
    </div>
  )
}