import { useNavigate } from 'react-router-dom'
import { Briefcase, Eye } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import UserAvatar from '@/pages/shared/components/UserAvatar/index.jsx'
import Badge from '@/components/ui/badge.jsx'

const fmt = (n) => new Intl.NumberFormat('fr-FR').format(n ?? 0)
const rate = (part, whole) => (whole > 0 ? `${((part / whole) * 100).toFixed(1)} %` : '—')

/**
 * Onglet « Employés » — les `COMERCIAL` rattachés à l'entreprise et leurs
 * compteurs (source : `GET entreprises/{id}/stats` → `employees`). Chaque
 * ligne ouvre la fiche employé (`/comercialDetail/:id`), qui garde le détail
 * par liste et l'historique individuel.
 */
export default function EmployeesCard({ employees = [], companyName }) {
  const navigate = useNavigate()

  if (employees.length === 0) {
    return (
      <div className="rounded-xl bg-card text-card-foreground shadow-sm p-6">
        <div className="h-24 flex items-center justify-center text-muted-foreground">
          Aucun employé rattaché à cette entreprise.
        </div>
      </div>
    )
  }

  return (
    <div className="rounded-xl bg-card text-card-foreground shadow-sm overflow-hidden">
      <div className="flex items-center gap-2 px-5 py-4 border-b border-border">
        <Briefcase className="h-4 w-4 text-muted-foreground" />
        <h3 className="text-sm font-semibold text-foreground">
          Employés ({employees.length})
        </h3>
        {companyName && (
          <span className="text-xs text-muted-foreground">— {companyName}</span>
        )}
      </div>

      <Table>
        <TableHeader>
          <TableRow className="bg-background hover:bg-background">
            <TableHead>Employé</TableHead>
            <TableHead>Adresse e-mail</TableHead>
            <TableHead className="text-right">Listes</TableHead>
            <TableHead className="text-right">Réservations</TableHead>
            <TableHead className="text-right">En attente</TableHead>
            <TableHead className="text-right">Appels</TableHead>
            <TableHead className="text-right">OUI</TableHead>
            <TableHead className="text-right">Taux de réussite</TableHead>
            <TableHead className="w-10" />
          </TableRow>
        </TableHeader>
        <TableBody>
          {employees.map((e) => (
            <TableRow
              key={e.id}
              className="cursor-pointer hover:bg-muted/50 transition-colors"
              onClick={() => navigate(`/comercialDetail/${e.id}`)}
            >
              <TableCell>
                <div className="flex items-center gap-2.5 min-w-0">
                  <UserAvatar user={e} size="sm" />
                  <div className="min-w-0">
                    <p className="font-medium truncate">{e.name}</p>
                    <Badge variant={e.status === 'ACTIVE' ? 'success' : 'secondary'} className="mt-0.5">
                      {e.status === 'ACTIVE' ? 'Actif' : e.status}
                    </Badge>
                  </div>
                </div>
              </TableCell>
              <TableCell className="text-muted-foreground truncate">{e.email ?? '—'}</TableCell>
              <TableCell className="text-right tabular-nums">{fmt(e.groups)}</TableCell>
              <TableCell className="text-right tabular-nums">{fmt(e.reservations)}</TableCell>
              <TableCell className="text-right tabular-nums">{fmt(e.pending)}</TableCell>
              <TableCell className="text-right tabular-nums">{fmt(e.calls)}</TableCell>
              <TableCell className="text-right tabular-nums">{fmt(e.calls_oui)}</TableCell>
              <TableCell className="text-right tabular-nums">{rate(e.clients_oui, e.clients_called)}</TableCell>
              <TableCell className="text-right">
                <button
                  onClick={(ev) => { ev.stopPropagation(); navigate(`/comercialDetail/${e.id}`) }}
                  className="p-1.5 rounded-lg hover:bg-muted transition-colors"
                  aria-label="Voir le détail de l'employé"
                  title="Voir"
                >
                  <Eye className="h-4 w-4 text-muted-foreground" />
                </button>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  )
}
