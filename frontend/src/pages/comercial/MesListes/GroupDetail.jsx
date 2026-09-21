import { ChevronRight, Home, ArrowLeft, Eye } from 'lucide-react'
import { useGroupDetail } from './useGroupDetail.js'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table.jsx'
import ClientStatusBadge from '@/pages/comercial/ProspectList/components/ProspectStatusBadge.jsx'
import UserAvatar from '@/pages/shared/components/UserAvatar/index.jsx'

export default function GroupDetailPage() {
  const {
    isLoading,
    group,
    reservations,
    isDesktop,
    handleMesListesClick,
    goBackClick,
    openProspectClick,
    openProspectStopClick,
    formatDate,
    formatExpiry,
  } = useGroupDetail()

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <nav className="flex items-center gap-1.5 text-sm text-muted-foreground">
        <a href="#" onClick={handleMesListesClick} className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
          <Home className="h-3.5 w-3.5" /> Accueil
        </a>
        <ChevronRight className="h-3.5 w-3.5" />
        <a href="#" onClick={handleMesListesClick} className="hover:text-foreground transition-colors">
          Mes listes
        </a>
        <ChevronRight className="h-3.5 w-3.5" />
        <span className="font-medium text-foreground">Détail</span>
      </nav>

      {isLoading ? (
        <div className="h-48 flex items-center justify-center text-muted-foreground">Chargement...</div>
      ) : !group ? (
        <div className="h-48 flex items-center justify-center text-muted-foreground">Liste introuvable.</div>
      ) : (
        <>
          <div className="flex items-start gap-3">
            <button onClick={goBackClick} className="p-1 rounded-md hover:bg-muted shrink-0">
              <ArrowLeft className="h-4 w-4" />
            </button>
            <div>
              <h1 className="text-2xl font-bold tracking-tight text-foreground">{group.name}</h1>
              <p className="text-sm text-muted-foreground mt-1">
                {group.reserved_count} prospect(s) réservé(s) sur {group.total} demandé(s) — {formatDate(group.created_at)}
              </p>
            </div>
          </div>

          {reservations.length === 0 ? (
            <div className="rounded-xl bg-card text-card-foreground shadow-sm h-32 flex items-center justify-center text-muted-foreground">
              Aucun prospect dans cette liste.
            </div>
          ) : isDesktop ? (
            <div className="rounded-xl bg-card text-card-foreground shadow-sm overflow-hidden">
              <Table>
                <TableHeader>
                  <TableRow className="bg-background hover:bg-background">
                    <TableHead>Prospect</TableHead>
                    <TableHead>Téléphone</TableHead>
                    <TableHead>Municipalité</TableHead>
                    <TableHead>Statut</TableHead>
                    <TableHead>Expire le</TableHead>
                    <TableHead className="w-10" />
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {reservations.map((r) => (
                    <TableRow
                      key={r.id}
                      className="cursor-pointer hover:bg-muted/50 transition-colors"
                      onClick={openProspectClick(r.client?.id)}
                    >
                      <TableCell>
                        <div className="flex items-center gap-2.5">
                          <UserAvatar user={r.client} size="sm" />
                          <div>
                            <span className="font-medium">{r.client?.name ?? '—'}</span>
                            <p className="text-xs text-muted-foreground">{r.client?.email ?? ''}</p>
                          </div>
                        </div>
                      </TableCell>
                      <TableCell className="text-muted-foreground">{r.client?.phone ?? '—'}</TableCell>
                      <TableCell className="text-muted-foreground">{r.client?.municipality ?? '—'}</TableCell>
                      <TableCell>
                        <ClientStatusBadge status={r.client?.status} />
                      </TableCell>
                      <TableCell className="text-muted-foreground">
                        {formatDate(r.expires_at)}
                      </TableCell>
                      <TableCell className="text-right">
                        <button
                          onClick={openProspectStopClick(r.client?.id)}
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
            </div>
          ) : (
            <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
              {reservations.map((r) => (
                <div
                  key={r.id}
                  className="relative flex flex-col rounded-xl border border-border bg-card text-card-foreground p-5 shadow-sm transition-all hover:shadow-md cursor-pointer"
                  onClick={openProspectClick(r.client?.id)}
                >
                  <div className="flex items-center gap-3 mb-3">
                    <UserAvatar user={r.client} size="md" />
                    <div className="min-w-0 flex-1">
                      <p className="text-sm font-semibold truncate">{r.client?.name ?? '—'}</p>
                      <p className="text-xs text-muted-foreground truncate">{r.client?.email ?? '—'}</p>
                    </div>
                  </div>
                  <div className="space-y-2 text-sm flex-1">
                    <div>
                      <span className="text-muted-foreground text-xs">Téléphone</span>
                      <p className="truncate">{r.client?.phone ?? '—'}</p>
                    </div>
                    <div>
                      <span className="text-muted-foreground text-xs">Municipalité</span>
                      <p className="truncate">{r.client?.municipality ?? '—'}</p>
                    </div>
                  </div>
                  <div className="flex items-center justify-between mt-4 pt-3 border-t border-border">
                    <ClientStatusBadge status={r.client?.status} />
                    <span className="text-xs text-muted-foreground">
                      {formatExpiry(r.expires_at)}
                    </span>
                  </div>
                </div>
              ))}
            </div>
          )}
        </>
      )}
    </div>
  )
}