import { Pencil, Power, Trash2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import StatusBadge from './StatusBadge'
import RowMenu from '@/pages/shared/components/RowMenu.jsx'
import SortHeader from '@/pages/shared/components/SortHeader.jsx'
import UserAvatar from '@/pages/shared/components/UserAvatar.jsx'

export default function EntrepriseTable({ entreprises, sortBy, sortOrder, onSort, onToggleStatus, onDelete, canDelete, onAvatarClick, onEdit }) {
  return (
    <div className="rounded-xl bg-card text-card-foreground shadow-sm overflow-hidden">
      <Table>
        <TableHeader>
          <TableRow className="bg-background hover:bg-background">
            <TableHead>
              <SortHeader column="name" currentSortBy={sortBy} sortOrder={sortOrder} onSort={onSort}>Entreprise</SortHeader>
            </TableHead>
            <TableHead>
              <SortHeader column="email" currentSortBy={sortBy} sortOrder={sortOrder} onSort={onSort}>Adresse e-mail</SortHeader>
            </TableHead>
            <TableHead>Téléphone</TableHead>
            <TableHead>NIF</TableHead>
            <TableHead>
              <SortHeader column="status" currentSortBy={sortBy} sortOrder={sortOrder} onSort={onSort}>Statut</SortHeader>
            </TableHead>
            <TableHead className="w-10" />
          </TableRow>
        </TableHeader>
        <TableBody>
          {entreprises.map((ent) => {
            const profil = ent.profil
            const name = profil?.nom ?? ent.email
            return (
              <TableRow key={ent.id}>
                <TableCell>
                  <div className="flex items-center gap-2.5">
                    <UserAvatar user={ent} size="sm" onEdit={() => onAvatarClick?.(ent)} />
                    <span className="font-medium">{name}</span>
                  </div>
                </TableCell>
                <TableCell className="text-muted-foreground">{ent.email}</TableCell>
                <TableCell className="text-muted-foreground">{profil?.telephone ?? '—'}</TableCell>
                <TableCell className="text-muted-foreground">{profil?.numero_fiscal ?? '—'}</TableCell>
                <TableCell><StatusBadge status={ent.status} /></TableCell>
                <TableCell className="text-right">
                  <RowMenu>
                    {(closeMenu) => (
                      <>
                        <button
                          onClick={() => { onEdit?.(ent); closeMenu() }}
                          className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-muted transition-colors"
                        >
                          <Pencil className="h-3.5 w-3.5" /> Modifier
                        </button>
                        <button
                          onClick={() => { onToggleStatus(ent.id, ent.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE'); closeMenu() }}
                          className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-muted transition-colors"
                        >
                          <Power className="h-3.5 w-3.5" /> {ent.status === 'ACTIVE' ? 'Désactiver' : 'Activer'}
                        </button>
                        {canDelete && (
                          <button
                            onClick={() => { onDelete(ent.id); closeMenu() }}
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
      {entreprises.length === 0 && (
        <div className="h-24 flex items-center justify-center text-muted-foreground">Aucun résultat.</div>
      )}
    </div>
  )
}