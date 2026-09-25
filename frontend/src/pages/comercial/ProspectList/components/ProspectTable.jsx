import { Eye } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import SortHeader from '@/pages/shared/components/SortHeader/index.jsx'
import UserAvatar from '@/pages/shared/components/UserAvatar/index.jsx'
import ClientStatus from '@/pages/shared/components/ClientStatus/index.jsx'
import { respondentsText, categoriesText } from './prospectFormat'

export default function ProspectTable({ clients, startIndex = 0, sortBy, sortOrder, onSort, onViewDetail, showViewButton = true }) {
  // Largeurs fixes par colonne (table-fixed) : le tableau garde sa largeur réelle
  // et le conteneur défile horizontalement (overflow-x) au lieu de couper les colonnes.
  const minWidth =
    60 + 240 + 200 + 150 + 150 + 180 + 220 + 140 + 170 +
    (showViewButton ? 60 : 0)

  return (
    <div className="rounded-xl bg-card text-card-foreground shadow-sm overflow-hidden">
      <div className="w-full overflow-x-auto" data-slot="prospect-scroll">
        <Table className="table-fixed" style={{ minWidth }}>
          <TableHeader>
            <TableRow className="bg-background hover:bg-background">
              <TableHead className="w-[60px] text-center">N°</TableHead>
              <TableHead className="w-[240px]">
                <SortHeader column="name" currentSortBy={sortBy} sortOrder={sortOrder} onSort={onSort}>
Prospect
                </SortHeader>
              </TableHead>
              <TableHead className="w-[200px]">Répondants</TableHead>
              <TableHead className="w-[150px]">N° de licence</TableHead>
              <TableHead className="w-[150px]">NEQ</TableHead>
              <TableHead className="w-[180px]">Catégorie</TableHead>
              <TableHead className="w-[220px]">
                <SortHeader column="email" currentSortBy={sortBy} sortOrder={sortOrder} onSort={onSort}>
                  Contact
                </SortHeader>
              </TableHead>
              <TableHead className="w-[140px]">Municipalité</TableHead>
              <TableHead className="w-[170px]">Statut</TableHead>
              {showViewButton && <TableHead className="w-[60px]" />}
            </TableRow>
          </TableHeader>
          <TableBody>
            {clients.map((c, i) => (
              <TableRow
                key={c.id}
                className="cursor-pointer hover:bg-muted/50 transition-colors"
                onClick={() => onViewDetail?.(c)}
              >
                <TableCell className="text-center text-muted-foreground tabular-nums">
                  {startIndex + i + 1}
                </TableCell>
                <TableCell>
                  <div
                    className="flex items-center gap-2.5"
                    title={[c.enterprise_name, c.name].filter(Boolean).join(' — ')}
                  >
                    <UserAvatar user={c} size="sm" />
                    <div className="min-w-0 flex-1">
                      <p className="truncate">{c.enterprise_name ?? '—'}</p>
                      <p className="truncate font-light text-small">{c.name}</p>
                    </div>
                  </div>
                </TableCell>
                <TableCell className="text-muted-foreground">
                  <div className="truncate" title={respondentsText(c) ?? undefined}>
                    {respondentsText(c) ?? '—'}
                  </div>
                </TableCell>
                <TableCell className="text-muted-foreground tabular-nums">
                  <div className="truncate" title={c.licence_number ?? undefined}>
                    {c.licence_number ?? '—'}
                  </div>
                </TableCell>
                <TableCell className="text-muted-foreground tabular-nums">
                  <div className="truncate" title={c.neq ?? undefined}>
                    {c.neq ?? '—'}
                  </div>
                </TableCell>
                <TableCell className="text-muted-foreground">
                  <div className="truncate" title={categoriesText(c) ?? undefined}>
                    {categoriesText(c) ?? '—'}
                  </div>
                </TableCell>
                <TableCell className="text-muted-foreground">
                  <div className="truncate" title={c.phone ?? undefined}>
                    {c.phone ?? '—'}
                  </div>
                  <div className="truncate" title={c.email ?? undefined}>
                    {c.email ?? '—'}
                  </div>
                </TableCell>
                <TableCell className="text-muted-foreground">
                  <div className="truncate" title={c.municipality ?? undefined}>
                    {c.municipality ?? '—'}
                  </div>
                </TableCell>
                <TableCell>
                  <ClientStatus
                    status={c.display_status ?? c.status}
                    isBlacklisted={c.is_blacklisted}
                    returnedAt={c.returned_at}
                  />
                </TableCell>
                {showViewButton && (
                  <TableCell className="text-right">
                    <button
                      onClick={(e) => { e.stopPropagation(); onViewDetail?.(c) }}
                      className="p-1.5 rounded-lg hover:bg-muted transition-colors"
                      aria-label="Voir détail"
                    >
                      <Eye className="h-4 w-4 text-muted-foreground" />
                    </button>
                  </TableCell>
                )}
              </TableRow>
            ))}
          </TableBody>
        </Table>
        {clients.length === 0 && (
          <div className="h-24 flex items-center justify-center text-muted-foreground">Aucun prospect trouvé.</div>
        )}
      </div>
    </div>
  )
}
