import { Copy, ArrowLeft, Phone, Ban, Loader2, AlertCircle, SquarePen, X, Unlock } from 'lucide-react'
import Button from '@/components/ui/button.jsx'
import ClientStatus from '@/pages/shared/components/ClientStatus/index.jsx'
import ReservationStatusBadge from '@/pages/comercial/ProspectList/components/ReservationStatusBadge.jsx'
import ClientDetailsTab from './components/ClientDetailsTab.jsx'
import NoteTimeline from './components/NoteTimeline.jsx'
import ActionModal from './components/ActionModal.jsx'
import PhoneEditModal from '@/pages/shared/components/PhoneEditModal/index.jsx'
import CallButton from '@/pages/shared/components/CallButton/index.jsx'
import { cn } from '@/lib/utils.js'
import { useClientDetail } from './useClientDetail.js'

export default function ClientDetailPage() {
  const {
    id,
    isAdmin,
    isLoading,
    client,
    notes,
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
    handleBack,
    handleCopyPhone,
    phoneOpen,
    openPhoneEdit,
    closePhoneEdit,
  } = useClientDetail()

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      {isLoading ? (
        <div className="h-48 flex items-center justify-center text-muted-foreground">Chargement...</div>
      ) : !client ? (
        <div className="h-48 flex items-center justify-center text-muted-foreground">Prospect introuvable.</div>
      ) : (
        <>
      <div className="rounded-xl bg-[#f8f9fa] border border-gray-200 overflow-hidden shadow-sm">
  {/* Top row: back arrow + title + phone + status badge */}
  <div className="px-4 sm:px-6 pt-5 pb-4">
    <div className="flex items-center gap-3">
      <Button variant="ghost" size="icon-sm" onClick={handleBack} aria-label="Retour">
        <ArrowLeft className="h-5 w-5 text-gray-700" />
      </Button>
      <div>
        <h1 className="text-lg sm:text-xl font-bold tracking-tight text-gray-900 truncate">
          {client.name ?? '—'}
        </h1>
        <p className="text-sm text-muted-foreground flex items-center">
          {client.phone ?? '—'}
          <button
            className="ml-2 text-gray-500 hover:text-gray-700 transition-colors" 
            onClick={handleCopyPhone} 
            title="Copier le numéro"
            type="button"
          >
            <Copy className="h-4 w-4" aria-hidden="true" />
          </button>
        </p>
      </div>

      {!isAdmin
        ? client.is_blacklisted
          ? null
          : <ReservationStatusBadge status={client.my_reservation?.status ?? 'PENDING'} />
        : (
            <ClientStatus
              status={client.display_status ?? client.status}
              isBlacklisted={client.is_blacklisted}
              returnedAt={client.returned_at}
            />
          )}
    </div>
  </div>

  {/* Underlined tabs and call actions */}
  <div className="px-4 sm:px-6 flex flex-col gap-3 border-t border-gray-200 sm:flex-row sm:items-center sm:justify-between">
    <nav className="flex gap-6 -mb-px" aria-label="Onglets du client" role="tablist">
      <button
        id="client-details-tab"
        type="button"
        role="tab"
        aria-controls="client-details-panel"
        aria-selected={activeTab === 'details'}
        onClick={() => setActiveTab('details')}
        className={cn(
          'border-b-2 px-1 py-3 text-sm transition-colors',
          activeTab === 'details'
            ? 'border-primary font-semibold text-primary'
            : 'border-transparent font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700',
        )}
      >
        Détails
      </button>
      <button
        id="client-history-tab"
        type="button"
        role="tab"
        aria-controls="client-history-panel"
        aria-selected={activeTab === 'history'}
        onClick={() => setActiveTab('history')}
        className={cn(
          'border-b-2 px-1 py-3 text-sm transition-colors',
          activeTab === 'history'
            ? 'border-primary font-semibold text-primary'
            : 'border-transparent font-medium text-gray-500 hover:border-gray-300 hover:text-gray-700',
        )}
      >
        Historique ({historyCount})
      </button>
    </nav>

  <div className="flex justify-end gap-3 pb-3 sm:pb-2">
      {!isAdmin && hasReservation ? (
        <>
          <CallButton
            phone={client.phone}
            name={client.name}
            label="Appeler"
            className="bg-emerald-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-emerald-700"
          />
          <Button
            onClick={openAction}
            variant="outline"
            className="flex items-center justify-center gap-2 border-gray-300 font-medium text-sm px-4 py-2 transition-colors"
          >
            <Phone className="h-4 w-4" />
            Suite appel
          </Button>
        </>
      ) : null}
  </div>
</div>
</div>
          {activeTab === 'details' ? (
          <section id="client-details-panel" role="tabpanel" aria-labelledby="client-details-tab" className="rounded-xl bg-card text-card-foreground shadow-sm p-6">
            <h2 id="client-details-heading" className="mb-4 text-sm font-semibold text-foreground">Détails</h2>
            <ClientDetailsTab client={client} />
          </section>
          ) : (
          <section id="client-history-panel" role="tabpanel" aria-labelledby="client-history-tab" className="rounded-xl bg-card text-card-foreground shadow-sm p-6">
            <h2 id="client-history-heading" className="mb-4 text-sm font-semibold text-foreground">
              Historique des interactions ({historyCount})
            </h2>
            <NoteTimeline notes={notes} readOnly={isAdmin} />
          </section>
          )}

          <div className="flex justify-end gap-2">
            {/* Saisie du numéro (Admin / Super Admin) — voir PhoneEditModal. */}
            {isAdmin && (
              <Button variant="outline" onClick={openPhoneEdit}>
                <SquarePen className="h-4 w-4 mr-1.5" />
                {client.phone ? 'Modifier le numéro' : 'Ajouter un numéro'}
              </Button>
            )}
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
                <Button variant="blacklist" onClick={openBlacklist}>
                  <Ban className="h-4 w-4 mr-1.5" />
                  BlackList
                </Button>
              )
            ) : (
              !client.is_blacklisted && (
                <Button variant="blacklist" onClick={openBlacklist}>
                  <Ban className="h-4 w-4 mr-1.5" />
                  BlackList
                </Button>
              )
            )}
          </div>
        </>
      )}

      <PhoneEditModal open={phoneOpen} client={client ?? null} onClose={closePhoneEdit} />

      <ActionModal
        open={actionOpen}
        onClose={closeAction}
        onActionSuccess={handleActionSuccess}
        clientId={id}
        hasReservation={hasReservation}
        reservedByName={reservedByName}
      />

      {blacklistOpen && (
        <div className="fixed inset-0 z-100 flex items-center justify-center bg-black/50 p-4" onClick={closeBlacklist}>
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
                  variant="blacklist"
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