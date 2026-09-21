import { ChevronRight, Home, Eye, RotateCcw } from 'lucide-react'
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
    releaseGroupClick,
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
                <TableHead>Réservés</TableHead>
                <TableHead>Total demandé</TableHead>
                <TableHead>En attente</TableHead>
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
                  <TableCell className="text-muted-foreground">{g.reserved_count}</TableCell>
                  <TableCell className="text-muted-foreground">{g.total}</TableCell>
                  <TableCell>
                    {g.pending_count > 0 ? (
                      <span className="inline-flex items-center rounded-full border border-amber-500/20 bg-amber-500/10 px-2.5 py-0.5 text-xs font-semibold text-amber-600 dark:text-amber-400">
                        {g.pending_count} en attente
                      </span>
                    ) : (
                      <span className="text-xs text-muted-foreground">—</span>
                    )}
                  </TableCell>
                  <TableCell className="text-muted-foreground">
                    {formatDate(g.created_at)}
                  </TableCell>
                  <TableCell className="text-right">
                    {g.pending_count > 0 && (
                      <button
                        onClick={releaseGroupClick(g.id)}
                        className="p-1.5 rounded-lg hover:bg-amber-500/10 text-amber-600 dark:text-amber-400 transition-colors"
                        title="Retourner à disponible"
                        aria-label="Retourner à disponible"
                      >
                        <RotateCcw className="h-4 w-4" />
                      </button>
                    )}
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
              {g.pending_count > 0 && (
                <button
                  onClick={releaseGroupClick(g.id)}
                  className="absolute top-3 right-3 p-1.5 rounded-lg hover:bg-amber-500/10 text-amber-600 dark:text-amber-400 transition-colors"
                  title="Retourner à disponible"
                  aria-label="Retourner à disponible"
                >
                  <RotateCcw className="h-4 w-4" />
                </button>
              )}
              <p className="text-sm font-semibold truncate">{g.name}</p>
              <div className="flex items-center gap-4 mt-2 text-sm text-muted-foreground">
                <span>{g.reserved_count} réservé(s)</span>
                <span>{g.total} demandé(s)</span>
              </div>
              {g.pending_count > 0 && (
                <span className="inline-flex items-center self-start mt-2 rounded-full border border-amber-500/20 bg-amber-500/10 px-2 py-0.5 text-xs font-semibold text-amber-600 dark:text-amber-400">
                  {g.pending_count} en attente
                </span>
              )}
              <span className="text-xs text-muted-foreground mt-3">
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