import { ChevronRight, Copy, Home, ArrowLeft, Phone, Ban, Loader2, AlertCircle, X, Unlock } from 'lucide-react'
import Button from '@/components/ui/button.jsx'
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs.jsx'
import ClientStatus from '@/pages/shared/components/ClientStatus/index.jsx'
import ClientDetailsTab from './components/ClientDetailsTab.jsx'
import NoteTimeline from './components/NoteTimeline.jsx'
import ActionModal from './components/ActionModal.jsx'
import { cn } from '@/lib/utils.js'
import { useClientDetail } from './useClientDetail.js'

export default function ClientDetailPage() {
  const {
    id,
    isAdmin,
    isLoading,
    client,
    notes,
    outcomes,
    historyCount,
    hasReservation,
    reservedByName,
    actionOpen,
    openAction,
    closeAction,
    handleActionSuccess,
    activeTab,
    setActiveTab,
    blacklistOpen,
    blacklistNote,
    blacklistError,
    handleBlacklistNoteChange,
    openBlacklist,
    closeBlacklist,
    blacklistMutation,
    blacklistConfirmDisabled,
    unblockMutation,
    handleHomeClick,
    handleProspectsClick,
    handleBack,
    handleCopyPhone,
  } = useClientDetail()

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <nav className="flex items-center gap-1.5 text-sm text-muted-foreground">
        <a href="#" onClick={handleHomeClick} className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
          <Home className="h-3.5 w-3.5" /> Accueil
        </a>
        <ChevronRight className="h-3.5 w-3.5" />
        <a href="#" onClick={handleProspectsClick} className="hover:text-foreground transition-colors">
          Prospects
        </a>
        <ChevronRight className="h-3.5 w-3.5" />
        <span className="font-medium text-foreground">Détail</span>
      </nav>

      {isLoading ? (
        <div className="h-48 flex items-center justify-center text-muted-foreground">Chargement...</div>
      ) : !client ? (
        <div className="h-48 flex items-center justify-center text-muted-foreground">Prospect introuvable.</div>
      ) : (
        <>
          <div className="flex items-start justify-between gap-3">
            <div className="flex items-start gap-3 min-w-0">
              <Button variant="ghost" size="icon-sm" onClick={handleBack}>
                <ArrowLeft className="h-4 w-4" />
              </Button>
              <div className="min-w-0">
                <div className="flex items-center gap-2">
                  <h1 className="text-2xl font-bold tracking-tight text-foreground truncate">{client.name ?? '—'}</h1>
                  <ClientStatus
                    status={client.display_status ?? client.status}
                    isBlacklisted={client.is_blacklisted}
                    returnedAt={client.returned_at}
                  />
                </div>
                <p className="text-sm text-muted-foreground">{client.enterprise_name ?? '—'}</p>
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1 text-sm text-muted-foreground">
                  <span>{client.representative_name ?? '—'}</span>
                  {client.email && (
                    <a href={`mailto:${client.email}`} className="hover:text-foreground transition-colors">{client.email}</a>
                  )}
                  {client.phone && (
                    <span className="inline-flex items-center gap-1">
                      {client.phone}
                      <button
                        type="button"
                        onClick={handleCopyPhone}
                        className="p-0.5 rounded hover:bg-muted hover:text-foreground transition-colors"
                        aria-label="Copier le numéro"
                      >
                        <Copy className="h-3.5 w-3.5" />
                      </button>
                    </span>
                  )}
                </div>
              </div>
            </div>
            <div className="flex items-center gap-2 shrink-0">
              {/* Bouton masqué si le client n'est pas réservé par le connecté (F-xx). */}
              {!isAdmin && hasReservation && (
                <Button onClick={openAction}>
                  <Phone className="h-4 w-4 mr-1" />
                  Suite appel
                </Button>
              )}
            </div>
          </div>

          <Tabs value={activeTab} onValueChange={setActiveTab}>
            <TabsList>
              <TabsTrigger value="details">Détails</TabsTrigger>
              <TabsTrigger value="history">Historique ({historyCount})</TabsTrigger>
            </TabsList>

            <TabsContent value="details">
              <div className="rounded-xl bg-card text-card-foreground shadow-sm p-6">
                <ClientDetailsTab client={client} />
              </div>
            </TabsContent>

            <TabsContent value="history">
              <div className="rounded-xl bg-card text-card-foreground shadow-sm p-6">
                <div className="flex items-center justify-between mb-4">
                  <h3 className="text-sm font-semibold text-foreground">Historique des interactions</h3>
                </div>
                <NoteTimeline notes={notes} outcomes={outcomes} clientId={id} readOnly={isAdmin} />
              </div>
            </TabsContent>
          </Tabs>

          <div className="flex justify-end">
            {isAdmin ? (
              client.is_blacklisted ? (
                <Button
                  variant="outline"
                  onClick={unblockMutation.mutate}
                  disabled={unblockMutation.isPending}
                >
                  {unblockMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin mr-1.5" /> : <Unlock className="h-4 w-4 mr-1.5" />}
                  Débloquer
                </Button>
              ) : (
                <Button
                  variant="outline"
                  className="border-destructive/30 text-destructive hover:bg-destructive/10"
                  onClick={openBlacklist}
                >
                  <Ban className="h-4 w-4 mr-1.5" />
                  Mettre en liste noire
                </Button>
              )
            ) : (
              !client.is_blacklisted && (
                <Button
                  variant="outline"
                  className="border-destructive/30 text-destructive hover:bg-destructive/10"
                  onClick={openBlacklist}
                >
                  <Ban className="h-4 w-4 mr-1.5" />
                  Mettre en liste noire
                </Button>
              )
            )}
          </div>
        </>
      )}

      <ActionModal
        open={actionOpen}
        onClose={closeAction}
        onActionSuccess={handleActionSuccess}
        clientId={id}
        hasReservation={hasReservation}
        reservedByName={reservedByName}
      />

      {blacklistOpen && (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4" onClick={closeBlacklist}>
          <div className="relative w-full max-w-md rounded-2xl bg-card p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-center justify-between mb-5">
              <h2 className="text-lg font-semibold text-foreground">Mettre en liste noire</h2>
              <button onClick={closeBlacklist} className="p-1 rounded-md hover:bg-muted" aria-label="Fermer">
                <X className="h-4 w-4" />
              </button>
            </div>

            <p className="text-sm text-muted-foreground mb-4">
              Ce prospect sera marqué comme indisponible pour tous les employés. Cette action est irréversible.
            </p>

            <div className="space-y-4">
              <div>
                <label className="block text-sm font-bold mb-1">Note (optionnel — 8 mots max)</label>
                <textarea
                  value={blacklistNote}
                  onChange={handleBlacklistNoteChange}
                  rows={3}
                  placeholder="Décrivez la raison de la mise en liste noire..."
                  className={cn(
                    'flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background',
                    'placeholder:text-muted-foreground',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
                  )}
                />
              </div>

              {blacklistError && (
                <div className="flex items-start gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
                  <AlertCircle className="h-4 w-4 mt-0.5 shrink-0" />
                  <span>{blacklistError}</span>
                </div>
              )}

              <div className="flex justify-end gap-2 pt-2">
                <Button type="button" variant="secondary" onClick={closeBlacklist} disabled={blacklistMutation.isPending}>
                  Annuler
                </Button>
                <Button
                  variant="destructive"
                  disabled={blacklistConfirmDisabled}
                  onClick={blacklistMutation.mutate}
                >
                  {blacklistMutation.isPending && <Loader2 className="h-4 w-4 animate-spin mr-2" />}
                  Confirmer
                </Button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}