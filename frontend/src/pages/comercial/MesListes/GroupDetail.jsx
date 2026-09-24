import { useEffect, useState } from 'react'
import { ChevronRight, Home, ArrowLeft, Eye, Pencil, Check, X, Loader2 } from 'lucide-react'
import { useGroupDetail } from './useGroupDetail.js'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table.jsx'
import ClientStatusBadge from '@/pages/comercial/ProspectList/components/ProspectStatusBadge.jsx'
import ReservationStatusBadge from '@/pages/comercial/ProspectList/components/ReservationStatusBadge.jsx'
import UserAvatar from '@/pages/shared/components/UserAvatar/index.jsx'
import Input from '@/components/ui/input.jsx'
import Button from '@/components/ui/button.jsx'

function GroupNameEditor({ group, renameMutation }) {
  const [editing, setEditing] = useState(false)
  const [value, setValue] = useState(group.name ?? '')

  useEffect(() => {
    setValue(group.name ?? '')
  }, [group.name])

  const save = (e) => {
    e?.stopPropagation()
    const name = value.trim()
    if (!name || name === group.name) {
      setEditing(false)
      return
    }
    renameMutation.mutate(name, { onSuccess: () => setEditing(false) })
  }

  if (!editing) {
    return (
      <div className="flex items-center gap-2">
        <h1 className="text-2xl font-bold tracking-tight text-foreground">{group.name}</h1>
        <button
          onClick={() => setEditing(true)}
          className="p-1.5 rounded-md hover:bg-muted text-muted-foreground"
          aria-label="Renommer la liste"
          title="Renommer la liste"
        >
          <Pencil className="h-4 w-4" />
        </button>
      </div>
    )
  }

  return (
    <div className="flex items-center gap-2" onClick={(e) => e.stopPropagation()}>
      <Input
        autoFocus
        value={value}
        onChange={(e) => setValue(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter') save(e)
          if (e.key === 'Escape') setEditing(false)
        }}
        className="h-9 w-72"
      />
      <Button size="sm" onClick={save} disabled={renameMutation.isPending}>
        {renameMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Check className="h-4 w-4" />}
      </Button>
      <Button size="sm" variant="secondary" onClick={() => setEditing(false)} disabled={renameMutation.isPending}>
        <X className="h-4 w-4" />
      </Button>
    </div>
  )
}

export default function GroupDetailPage() {
  const {
    isLoading,
    group,
    reservations,
    hiddenCount,
    isDesktop,
    renameMutation,
    handleMesListesClick,
    goBackClick,
    openProspectClick,
    openProspectStopClick,
    formatDate,
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
              <GroupNameEditor group={group} renameMutation={renameMutation} />
              <p className="text-sm text-muted-foreground mt-1">
                {group.reserved_count} prospect(s) réservé(s) sur {group.total} demandé(s) — {formatDate(group.created_at)}
                {hiddenCount > 0 && (
                  <span className="ml-2 text-xs text-muted-foreground">
                    ({hiddenCount} rappel(s) planifié(s) masqué(s) — voir « Rappels » / « Auto-rappels »)
                  </span>
                )}
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
                    <TableHead>Réservation</TableHead>
                    <TableHead className="w-10" />
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {reservations.map((r) => (
                    <TableRow
                      key={r.id}
                      className={`cursor-pointer transition-colors ${
                        r.status === 'BV' || r.status === 'INJOINABLE'
                          ? 'bg-muted/40 hover:bg-muted/70'
                          : 'hover:bg-muted/50'
                      }`}
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
                      <TableCell>
                        <ReservationStatusBadge status={r.status} />
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
                  className={`relative flex flex-col rounded-xl border border-border text-card-foreground p-5 shadow-sm transition-all hover:shadow-md cursor-pointer ${
                    r.status === 'BV' || r.status === 'INJOINABLE'
                      ? 'bg-muted/40'
                      : 'bg-card'
                  }`}
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
                  <div className="flex items-center justify-between gap-2 mt-4 pt-3 border-t border-border">
                    <ClientStatusBadge status={r.client?.status} />
                    <ReservationStatusBadge status={r.status} />
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
