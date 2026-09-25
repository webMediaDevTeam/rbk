import { Eye } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import ClientStatus from '@/pages/shared/components/ClientStatus/index.jsx'
import UserAvatar from '@/pages/shared/components/UserAvatar/index.jsx'
import { useHistoryTable } from './useHistoryTable.js'

export default function HistoryTable(props) {
  const { rows } = useHistoryTable(props)

  return (
    <div className="rounded-xl bg-card text-card-foreground shadow-sm overflow-hidden">
      <Table>
        <TableHeader>
          <TableRow className="bg-background hover:bg-background">
            <TableHead>Prospect</TableHead>
            <TableHead>Email</TableHead>
            <TableHead>Téléphone</TableHead>
            <TableHead>Municipalité</TableHead>
            <TableHead>Statut</TableHead>
            <TableHead>Fin licence</TableHead>
            <TableHead className="w-10" />
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((row) => (
            <TableRow
              key={row.key}
              className="cursor-pointer hover:bg-muted/50 transition-colors"
              onClick={row.handleRowClick}
            >
              <TableCell>
                <div className="flex items-center gap-2.5">
                  <UserAvatar user={row.c} size="sm" />
                  <div>
                    <span className="font-medium">{row.c.name}</span>
                    <p className="text-xs text-muted-foreground">{row.c.enterprise_name}</p>
                  </div>
                </div>
              </TableCell>
              <TableCell className="text-muted-foreground">{row.c.email ?? '—'}</TableCell>
              <TableCell className="text-muted-foreground">{row.c.phone ?? '—'}</TableCell>
              <TableCell className="text-muted-foreground">{row.c.municipality ?? '—'}</TableCell>
              <TableCell>
                <ClientStatus
                  status={row.c.display_status ?? row.c.status}
                  isBlacklisted={row.c.is_blacklisted}
                  returnedAt={row.c.returned_at}
                />
              </TableCell>
              <TableCell className="text-muted-foreground">{row.licenceDate}</TableCell>
              <TableCell className="text-right">
                <button
                  onClick={row.handleEyeClick}
                  className="p-1.5 rounded-lg hover:bg-muted transition-colors"
                  aria-label="Voir détail"
                >
                  <Eye className="h-4 w-4 text-muted-foreground" />
                </button>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
      {rows.length === 0 && (
        <div className="h-24 flex items-center justify-center text-muted-foreground">Aucun prospect trouvé.</div>
      )}
    </div>
  )
}