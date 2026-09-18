import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, Home, Eye, RotateCcw } from 'lucide-react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { listReservationGroupsApi, releaseGroupPendingApi } from '@/api/commercial.api.js'
import { useIsDesktop } from '@/hooks/use-mobile.js'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table.jsx'
import Pagination from '@/pages/shared/users/components/Pagination.jsx'
import Button from '@/components/ui/button.jsx'
import { toast } from 'sonner'

export default function MesListesPage() {
  const navigate = useNavigate()
  const qc = useQueryClient()
  const isDesktop = useIsDesktop()
  const [currentPage, setCurrentPage] = useState(1)
  const [rowsPerPage, setRowsPerPage] = useState(20)

  const { data, isLoading } = useQuery({
    queryKey: ['reservation-groups', currentPage, rowsPerPage],
    queryFn: () => listReservationGroupsApi({ page: currentPage, per_page: rowsPerPage }),
  })

  const groups = data?.data?.groups ?? []
  const total = data?.data?.pagination?.total ?? 0

  const releaseMutation = useMutation({
    mutationFn: (groupId) => releaseGroupPendingApi(groupId),
    onSuccess: (res, groupId) => {
      const released = res?.data?.released ?? 0
      qc.invalidateQueries({ queryKey: ['reservation-groups'] })
      qc.invalidateQueries({ queryKey: ['reservations-pending'] })
      toast.success(`${released} prospect(s) retourné(s) à disponible.`)
    },
    onError: (err) => {
      toast.error(err?.response?.data?.message || 'Une erreur est survenue.')
    },
  })

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <nav className="flex items-center gap-1.5 text-sm text-muted-foreground">
        <a href="#" onClick={(e) => e.preventDefault()} className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
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
                  onClick={() => navigate(`/mes-listes/${g.id}`)}
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
                    {g.created_at ? new Date(g.created_at).toLocaleDateString('fr-FR') : '—'}
                  </TableCell>
                  <TableCell className="text-right">
                    {g.pending_count > 0 && (
                      <button
                        onClick={(e) => { e.stopPropagation(); releaseMutation.mutate(g.id) }}
                        className="p-1.5 rounded-lg hover:bg-amber-500/10 text-amber-600 dark:text-amber-400 transition-colors"
                        title="Retourner à disponible"
                        aria-label="Retourner à disponible"
                      >
                        <RotateCcw className="h-4 w-4" />
                      </button>
                    )}
                    <button
                      onClick={(e) => { e.stopPropagation(); navigate(`/mes-listes/${g.id}`) }}
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
              onClick={() => navigate(`/mes-listes/${g.id}`)}
            >
              {g.pending_count > 0 && (
                <button
                  onClick={(e) => { e.stopPropagation(); releaseMutation.mutate(g.id) }}
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
                {g.created_at ? new Date(g.created_at).toLocaleDateString('fr-FR') : '—'}
              </span>
            </div>
          ))}
        </div>
      )}

      <Pagination
        currentPage={currentPage}
        totalPages={Math.max(1, Math.ceil(total / rowsPerPage))}
        rowsPerPage={rowsPerPage}
        onPageChange={setCurrentPage}
        onRowsPerPageChange={(n) => { setRowsPerPage(n); setCurrentPage(1) }}
      />
    </div>
  )
}