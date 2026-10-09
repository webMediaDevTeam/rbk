import { useEffect, useState } from 'react'
import { ArrowLeft, Eye, Pencil, Check, X, Loader2, Hourglass, ThumbsUp, ThumbsDown, Voicemail, PhoneOff, Users, Copy, Info, SquarePen } from 'lucide-react'
import KpiPill, { KpiBar, formatCount } from '@/pages/shared/components/KpiPill/index.jsx'
import { useGroupDetail } from './useGroupDetail.js'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table.jsx'
import ProspectStatus from '@/pages/shared/components/ProspectStatus/index.jsx'
import UserAvatar from '@/pages/shared/components/UserAvatar/index.jsx'
import Input from '@/components/ui/input.jsx'
import Button from '@/components/ui/button.jsx'
import CallButton from '@/pages/shared/components/CallButton/index.jsx'
import ClientEditModal from '@/pages/shared/components/ClientEditModal/index.jsx'

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
  const [clientToEdit, setClientToEdit] = useState(null)
  const {
    isLoading,
    group,
    reservations,
    filteredReservations,
    statusFilter,
    handleStatusFilterChange,
    rappelCount,
    isDesktop,
    renameMutation,
    goBackClick,
    openProspectClick,
    openProspectStopClick,
    formatDate,
  } = useGroupDetail()

  // Barre de badges de statut de réservation — **même structure que la page
  // « Grande liste »** (ProspectKpis) : `Tous` en 1er, puis les statuts
  // dans l'ordre du workflow (`En attente` en 2e), sélection unique, couleur
  // pleine à la sélection et **tous les compteurs affichés, même à 0**.
  const STATUS_PILLS = [
    {
      key: 'PENDING',
      label: 'En attente',
      icon: Hourglass,
      iconClass: 'bg-slate-500/10 text-slate-600 dark:text-slate-400',
      activeClass: 'border border-transparent bg-slate-600',
      activeFg: 'text-white',
      title: 'Réservations pas encore appelées (PENDING) — clique pour filtrer',
    },
    {
      key: 'YES',
      label: 'Confirmé',
      icon: ThumbsUp,
      iconClass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
      activeClass: 'border border-transparent bg-emerald-700',
      activeFg: 'text-white',
      title: 'Réservations au statut YES — clique pour filtrer',
    },
    {
      key: 'NO',
      label: 'Refusé',
      icon: ThumbsDown,
      iconClass: 'bg-destructive/10 text-destructive',
      activeClass: 'border border-transparent bg-destructive',
      activeFg: 'text-white',
      title: 'Réservations au statut NO — clique pour filtrer',
    },
    {
      key: 'BV_VOICEMAIL',
      label: 'BV',
      icon: Voicemail,
      iconClass: 'bg-amber-500/10 text-[var(--warning-fg)]',
      activeClass: 'border border-transparent bg-amber-700',
      activeFg: 'text-white',
      title: 'Réservations au statut BV_VOICEMAIL — clique pour filtrer',
    },
    {
      key: 'CALL_BACK',
      label: 'À rapp..',
      icon: PhoneOff,
      iconClass: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
      activeClass: 'border border-transparent bg-sky-600',
      activeFg: 'text-white',
      title: 'Réservations au statut CALL_BACK — clique pour filtrer',
    },
    {
      key: 'DOUBLE',
      label: 'Double',
      icon: Copy,
      iconClass: 'bg-primary/10 text-primary',
      activeClass: 'border border-transparent bg-primary',
      activeFg: 'text-white',
      title: 'Réservations au statut DOUBLE — clique pour filtrer',
    },
    {
      key: 'INFO',
      label: 'Info',
      icon: Info,
      iconClass: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
      activeClass: 'border border-transparent bg-sky-600',
      activeFg: 'text-white',
      title: 'Réservations au statut INFO — clique pour filtrer',
    },
  ]

  // Compteurs des badges calculés sur **toutes** les lignes de la liste
  // (`reservations`), pas sur la sélection de statut : le badge dit toujours
  // le vrai total, quel que soit le filtre actif.
  const counts = reservations.reduce((acc, r) => {
    acc[r.status] = (acc[r.status] ?? 0) + 1
    return acc
  }, {})

  const tousPill = {
    key: 'ALL',
    label: 'Tous',
    primary: reservations.length,
    value: formatCount(reservations.length),
    icon: Users,
    iconClass: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    activeClass: 'border border-transparent bg-blue-600',
    activeFg: 'text-white',
    active: statusFilter === null,
    onClick: () => handleStatusFilterChange(null),
    title: 'Tous les prospects de la liste — clique pour retirer tous les filtres de statut',
  }

  const statusPills = STATUS_PILLS.map((pill) => ({
    ...pill,
    primary: counts[pill.key] ?? 0,
    value: formatCount(counts[pill.key] ?? 0),
    active: statusFilter === pill.key,
    onClick: () => handleStatusFilterChange(pill.key),
    title: `${pill.title} (${counts[pill.key] ?? 0})`,
  }))

  // État de réservation (tableau **et** cartes mobiles) : « En attente »
  // (`PENDING`) affiche simplement `—` — la ligne reste en fond normal
  // (le gris est passé sur les lignes déjà traitées).
  // Une seule valeur par ligne (docs/RULES.md §9) : statut client si le
  // prospect est blacklisté ou (re)disponible, sinon statut de la
  // réservation courante — « En attente » s'affiche « - ».
  const statusCell = (r) => (
    <ProspectStatus
      status={r.client?.status}
      displayStatus={r.client?.display_status}
      reservationStatus={r.status}
      isBlacklisted={r.client?.is_blacklisted}
      returnedAt={r.client?.returned_at}
    />
  )

  return (
    <div className="max-w-7xl mx-auto space-y-6">
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
                {rappelCount > 0 && (
                  <span className="ml-2 text-xs text-muted-foreground">
                    ({rappelCount} rappel(s) planifié(s) — voir « Rappels » / « Auto-rappels »)
                  </span>
                )}
              </p>
            </div>
          </div>

          {/* Barre de filtres par statut de réservation — même structure que
              ProspectKpis : « Tous » (1er) retire toutes les sélections,
              `En attente` est sélectionné dès l'ouverture de la page. */}
          <KpiBar>
            <KpiPill {...tousPill} />
            {statusPills.map((pill) => (
              <KpiPill key={pill.key} {...pill} />
            ))}
          </KpiBar>

          {reservations.length === 0 ? (
            <div className="rounded-xl bg-card text-card-foreground shadow-sm h-32 flex items-center justify-center text-muted-foreground">
              Aucun prospect dans cette liste.
            </div>
          ) : filteredReservations.length === 0 ? (
            <div className="rounded-xl bg-card text-card-foreground shadow-sm h-32 flex items-center justify-center text-muted-foreground">
              Aucun prospect pour ce statut.
            </div>
          ) : isDesktop ? (
            <div className="rounded-xl bg-card text-card-foreground shadow-sm overflow-hidden">
              <Table>
                <TableHeader>
                  <TableRow className="bg-background hover:bg-background">
                    <TableHead>Prospect</TableHead>
                    <TableHead>Téléphone</TableHead>
                    <TableHead>Municipalité</TableHead>
                    <TableHead>État</TableHead>
                    <TableHead>Rappel</TableHead>
                    <TableHead className="w-16" />
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {filteredReservations.map((r) => (
                    <TableRow
                      key={r.id}
                      // Fond gris inversé : les lignes **déjà traitées**
                      // (statut ≠ PENDING) passent en `row-dimmed`
                      // (gris clair / gris foncé en dark) ; les lignes
                      // « En attente » gardent un fond normal.
                      className={`cursor-pointer transition-colors ${
                        r.status === 'PENDING' ? 'hover:bg-muted/50' : 'row-dimmed'
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
                      {/* État = une seule valeur : statut client si
                          blacklisté/disponible, sinon réservation (§9). */}
                      <TableCell>{statusCell(r)}</TableCell>
                      {/* Rappel planifié (BV / À rappeler) : la ligne reste
                          affichée, on montre juste la date de retour. */}
                      <TableCell className="text-muted-foreground text-xs whitespace-nowrap">
                        {r.recall_at ? `Retour le ${formatDate(r.recall_at)}` : '—'}
                      </TableCell>
                      <TableCell className="text-right">
                        {/* Appel direct : `from` = numéro de l'employé
                            (résolu par l'API), `to` = numéro du client. */}
                        <div className="flex items-center justify-end gap-1">
                          <CallButton phone={r.client?.phone} name={r.client?.name} clientId={r.client?.id} />
                          {r.client && !r.client.is_blacklisted && ['RESERVED', 'CONFIRMED', 'DOUBLE', 'INFO'].includes(r.client.status) && (
                            <button
                              type="button"
                              onClick={(event) => {
                                event.stopPropagation()
                                setClientToEdit(r.client)
                              }}
                              className="rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                              aria-label={`Modifier la fiche de ${r.client.name ?? 'ce prospect'}`}
                              title="Modifier la fiche"
                            >
                              <SquarePen className="h-4 w-4" />
                            </button>
                          )}
                          <button
                            type="button"
                            onClick={openProspectStopClick(r.client?.id)}
                            className="p-1.5 rounded-lg hover:bg-muted transition-colors"
                            aria-label="Voir détail"
                          >
                            <Eye className="h-4 w-4 text-muted-foreground" />
                          </button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          ) : (
            <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
              {filteredReservations.map((r) => (
                <div
                  key={r.id}
                  // Fond gris inversé : cartes **déjà traitées** en
                  // `row-dimmed`, « En attente » en fond carte normal.
                  className={`relative flex flex-col rounded-xl border border-border text-card-foreground p-5 shadow-sm transition-all hover:shadow-md cursor-pointer ${
                    r.status === 'PENDING' ? 'bg-card' : 'row-dimmed'
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
                    {/* Rappel planifié (BV / À rappeler) : ligne affichée,
                        date de retour indiquée. */}
                    {r.recall_at && (
                      <div>
                        <span className="text-muted-foreground text-xs">Rappel</span>
                        <p className="truncate">Retour le {formatDate(r.recall_at)}</p>
                      </div>
                    )}
                  </div>
                  {/* État = une seule valeur, comme la colonne du tableau
                      (docs/RULES.md §9) + appel direct du client. */}
                  <div className="flex items-center justify-between gap-2 mt-4 pt-3 border-t border-border">
                    {statusCell(r)}
                    <div className="flex items-center gap-1">
                      <CallButton phone={r.client?.phone} name={r.client?.name} clientId={r.client?.id} />
                      {r.client && !r.client.is_blacklisted && ['RESERVED', 'CONFIRMED', 'DOUBLE', 'INFO'].includes(r.client.status) && (
                        <button
                          type="button"
                          onClick={(event) => {
                            event.stopPropagation()
                            setClientToEdit(r.client)
                          }}
                          className="rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                          aria-label={`Modifier la fiche de ${r.client.name ?? 'ce prospect'}`}
                          title="Modifier la fiche"
                        >
                          <SquarePen className="h-4 w-4" />
                        </button>
                      )}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </>
      )}
      <ClientEditModal
        open={!!clientToEdit}
        client={clientToEdit}
        onClose={() => setClientToEdit(null)}
      />
    </div>
  )
}
