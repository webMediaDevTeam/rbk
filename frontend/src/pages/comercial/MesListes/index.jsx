import { Eye } from 'lucide-react'
import { useMesListes } from './useMesListes.js'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table.jsx'
import Pagination from '@/pages/shared/components/Pagination/index.jsx'

/**
 * Page « Mes listes » — tableau épuré (plus de badge de statistiques) :
 *
 *   Liste      : employé + date de création, **calculés à l'affichage**
 *                (le champ `name` sauvegardé n'est plus affiché) ;
 *   Clients    : réservations réellement créées ;
 *   État       : traités / clients de la liste ;
 *   OUI, NON, BV, À rappeler (statut CALL_BACK), Blacklist ;
 *   + colonnes supprimées : Demandé, Injoinable, Restant, Employé, Créé le.
 *
 * Fond gris inversé : la **liste courante** (la plus récente, 1re ligne de
 * la 1re page) reste en fond normal (blanc / noir en dark), ce sont les
 * **anciennes listes** qui portent la classe `row-dimmed`
 * (`--row-highlight`, `styles/theme.css`).
 */
export default function MesListesPage() {
  const {
    isLoading,
    groups,
    isDesktop,
    currentPage,
    totalPages,
    rowsPerPage,
    currentGroupId,
    openGroupClick,
    openGroupStopClick,
    handlePageChange,
    handleRowsPerPageChange,
    formatDateTime,
  } = useMesListes()

  const isCurrent = (g) => g.id === currentGroupId

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-foreground">Mes listes</h1>
        <p className="text-sm text-muted-foreground mt-1">Groupes de réservations.</p>
      </div>

      {/* Badge de statistiques « Listes » supprimé à la demande. */}

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
                <TableHead>Liste</TableHead>
             
                <TableHead className="text-center">État</TableHead>
                <TableHead className="text-center">OUI</TableHead>
                <TableHead className="text-center">NON</TableHead>
                <TableHead className="text-center">BV</TableHead>
                <TableHead className="text-center">À rappeler</TableHead>
                <TableHead className="text-center">Blacklist</TableHead>
                <TableHead className="w-10" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {groups.map((g) => (
                <TableRow
                  key={g.id}
                  className={`cursor-pointer hover:bg-primary/10 transition-colors${isCurrent(g) ? '' : ' row-dimmed'}`}
                  onClick={openGroupClick(g.id)}
                >
                  {/* Nom dynamique : employé + date de création. */}
                  <TableCell>
                    <span className="font-medium">{g.employe || '—'}</span>
                    <span className="block text-xs text-muted-foreground mt-0.5">
                      {formatDateTime(g.created_at)}
                    </span>
                  </TableCell>
               
                  <TableCell className="text-center text-muted-foreground tabular-nums">
                    {g.traites_count ?? 0}/{g.clients_count ?? 0}
                  </TableCell>
                  <TableCell className="text-center tabular-nums">{g.oui_count ?? 0}</TableCell>
                  <TableCell className="text-center tabular-nums">{g.non_count ?? 0}</TableCell>
                  <TableCell className="text-center tabular-nums">{g.bv_count ?? 0}</TableCell>
                  <TableCell className="text-center tabular-nums">{g.injoinable_count ?? 0}</TableCell>
                  <TableCell className="text-center tabular-nums">{g.blacklist_count ?? 0}</TableCell>
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
              className={`relative flex flex-col rounded-xl border border-border bg-card text-card-foreground p-5 shadow-sm transition-all hover:shadow-md cursor-pointer${isCurrent(g) ? '' : ' row-dimmed'}`}
              onClick={openGroupClick(g.id)}
            >
              {/* Nom dynamique : employé + date de création. */}
              <p className="text-sm font-semibold truncate">{g.employe || '—'}</p>
              <p className="text-xs text-muted-foreground mt-0.5">{formatDateTime(g.created_at)}</p>
              <div className="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2 text-sm text-muted-foreground">
                <span>{g.clients_count ?? 0} client(s)</span>
                <span>État {g.traites_count ?? 0}/{g.clients_count ?? 0}</span>
              </div>
              <div className="flex flex-wrap gap-x-4 gap-y-1 mt-1 text-xs text-muted-foreground">
                <span>OUI {g.oui_count ?? 0}</span>
                <span>NON {g.non_count ?? 0}</span>
                <span>BV {g.bv_count ?? 0}</span>
                <span>À rappeler {g.injoinable_count ?? 0}</span>
                <span>Blacklist {g.blacklist_count ?? 0}</span>
              </div>
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
