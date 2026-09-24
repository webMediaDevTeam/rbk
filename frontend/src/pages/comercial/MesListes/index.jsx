import { ChevronRight, Home, Eye } from 'lucide-react'
import { useMesListes } from './useMesListes.js'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table.jsx'
import Pagination from '@/pages/shared/components/Pagination/index.jsx'

export default function MesListesPage() {
  const {
    isLoading,
    groups,
    isDesktop,
    currentPage,
    totalPages,
    rowsPerPage,
    handleAccueilClick,
    openGroupClick,
    openGroupStopClick,
    handlePageChange,
    handleRowsPerPageChange,
    formatDate,
  } = useMesListes()

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <nav className="flex items-center gap-1.5 text-sm text-muted-foreground">
        <a href="#" onClick={handleAccueilClick} className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
          <Home className="h-3.5 w-3.5" /> Accueil
        </a>
        <ChevronRight className="h-3.5 w-3.5" />
        <span className="font-medium text-foreground">Mes listes</span>
      </nav>

      <div>
        <h1 className="text-2xl font-bold tracking-tight text-foreground">Mes listes</h1>
        <p className="text-sm text-muted-foreground mt-1">Groupes de réservations.</p>
      </div>

      {isLoading ? (
        <div className="h-48 flex items-center justify-center text-muted-foreground">Chargement...</div>
      ) : groups.length === 0 ? (
        <div className="rounded-xl bg-card text-card-foreground shadow-sm h-48 flex items-center justify-center text-muted-foreground">
          Aucune liste. Créez une réservation depuis la page Prospects.
        </div>
      ) : isDesktop ? (
        <div className="rounded-xl bg-card text-card-foreground shadow-sm overflow-hidden">
          <Table>
            <TableHeader>
              <TableRow className="bg-background hover:bg-background">
                <TableHead>Nom</TableHead>
                <TableHead className="text-center">Clients</TableHead>
                <TableHead className="text-center">Demandé</TableHead>
                <TableHead className="text-center">Traités</TableHead>
                <TableHead className="text-center">OUI</TableHead>
                <TableHead className="text-center">NON</TableHead>
                <TableHead className="text-center">BV</TableHead>
                <TableHead className="text-center">Injoinable</TableHead>
                <TableHead className="text-center">Restant</TableHead>
                <TableHead>Employé</TableHead>
                <TableHead>Créé le</TableHead>
                <TableHead className="w-10" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {groups.map((g) => (
                <TableRow
                  key={g.id}
                  className="cursor-pointer hover:bg-primary/10 transition-colors"
                  onClick={openGroupClick(g.id)}
                >
                  <TableCell className="font-medium">{g.name}</TableCell>
                  <TableCell className="text-center text-muted-foreground tabular-nums">{g.clients_count ?? 0}</TableCell>
                  <TableCell className="text-center text-muted-foreground tabular-nums">{g.total}</TableCell>
                  <TableCell className="text-center text-muted-foreground tabular-nums">{g.traites_count ?? 0}</TableCell>
                  <TableCell className="text-center tabular-nums">{g.oui_count ?? 0}</TableCell>
                  <TableCell className="text-center tabular-nums">{g.non_count ?? 0}</TableCell>
                  <TableCell className="text-center tabular-nums">{g.bv_count ?? 0}</TableCell>
                  <TableCell className="text-center tabular-nums">{g.injoinable_count ?? 0}</TableCell>
                  <TableCell className="text-center tabular-nums">{g.restant_count ?? 0}</TableCell>
                  <TableCell className="text-muted-foreground">{g.employe ?? '—'}</TableCell>
                  <TableCell className="text-muted-foreground">
                    {formatDate(g.created_at)}
                  </TableCell>
                  <TableCell className="text-right">
                    <button
                      onClick={openGroupStopClick(g.id)}
                      className="p-1.5 rounded-lg hover:bg-muted transition-colors"
                      aria-label="Voir"
                    >
                      <Eye className="h-4 w-4 text-muted-foreground" />
                    </button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
          {groups.map((g) => (
            <div
              key={g.id}
              className="relative flex flex-col rounded-xl border border-border bg-card text-card-foreground p-5 shadow-sm transition-all hover:shadow-md cursor-pointer"
              onClick={openGroupClick(g.id)}
            >
              <p className="text-sm font-semibold truncate">{g.name}</p>
              <div className="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2 text-sm text-muted-foreground">
                <span>{g.clients_count ?? 0} client(s)</span>
                <span>{g.total} demandé(s)</span>
                <span>{g.traites_count ?? 0} traité(s)</span>
                <span>{g.restant_count ?? 0} restant(s)</span>
              </div>
              <div className="flex flex-wrap gap-x-4 gap-y-1 mt-1 text-xs text-muted-foreground">
                <span>OUI {g.oui_count ?? 0}</span>
                <span>NON {g.non_count ?? 0}</span>
                <span>BV {g.bv_count ?? 0}</span>
                <span>Injoinable {g.injoinable_count ?? 0}</span>
              </div>
              <p className="text-xs text-muted-foreground mt-1 truncate">Employé : {g.employe ?? '—'}</p>
              <span className="text-xs text-muted-foreground mt-3 block">
                {formatDate(g.created_at)}
              </span>
            </div>
          ))}
        </div>
      )}

      <Pagination
        currentPage={currentPage}
        totalPages={totalPages}
        rowsPerPage={rowsPerPage}
        onPageChange={handlePageChange}
        onRowsPerPageChange={handleRowsPerPageChange}
      />
    </div>
  )
}