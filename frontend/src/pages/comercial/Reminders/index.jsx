import { Loader2, BellOff } from 'lucide-react'
import { useRemindersPage } from './useRemindersPage.js'
import ReminderTable from './components/ReminderTable.jsx'
import ReminderCard from './components/ReminderCard.jsx'
import ReminderToolbar from './components/ReminderToolbar.jsx'
import Pagination from '@/pages/shared/components/Pagination/index.jsx'
import ProspectKpis from '@/pages/shared/components/ProspectKpis/index.jsx'

/**
 * Liste des rappels du commercial connecté — **même trame que la page
 * Prospects** : en-tête, barre de badges de statut, recherche + filtre
 * municipalité, tableau (desktop) / cartes (mobile), pagination.
 *
 * type : 'CALL_BACK' (page « Rappels ») ou 'BV' (page « Auto-rappels »).
 *
 * **Page consultative** : un seul contrôle par ligne, l'œil « Voir » →
 * l'historique du client. Ligne obsolète (rappel terminé, statut changé ou
 * suivi plus récent) : l'œil est remplacé par la pastille « Obsolète ».
 * Les badges de statut s'affichent en **lecture seule** (la page ne liste
 * qu'un type de réservation) : ils donnent les chiffres globaux.
 */
export default function RemindersPage({
  type = 'CALL_BACK',
  title = 'À rappeler',
  subtitle = 'Clients injoignables en attente de rappel.',
  emptyText = 'Aucun rappel en attente.',
}) {
  const {
    isDesktop,
    isLoading,
    reminders,
    rows,
    startIndex,
    search, setSearch,
    municipality, handleMunicipalityChange,
    sortBy, sortOrder, handleSort,
    currentPage, setCurrentPage,
    rowsPerPage, handleRowsPerPageChange,
    totalPages,
    canView,
    handleViewHistory,
    formatRecallAt,
    formatRecallFull,
  } = useRemindersPage(type)

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">{title}</h1>
          <p className="text-sm text-muted-foreground mt-1">{subtitle}</p>
        </div>

        {!isLoading && reminders.length > 0 && (
          <span className="inline-flex items-center gap-1.5 rounded-full bg-teal-600/10 px-3 py-1 text-sm font-semibold text-teal-700 dark:text-teal-400">
            <BellOff className="h-4 w-4" />
            {reminders.length} en attente
          </span>
        )}
      </div>

   

      <ReminderToolbar
        search={search}
        setSearch={setSearch}
        municipality={municipality}
        setMunicipality={handleMunicipalityChange}
      />

      {isLoading ? (
        <div className="h-48 flex items-center justify-center gap-2 text-muted-foreground">
          <Loader2 className="h-5 w-5 animate-spin" /> Chargement...
        </div>
      ) : isDesktop ? (
        <ReminderTable
          reminders={rows}
          startIndex={startIndex}
          sortBy={sortBy}
          sortOrder={sortOrder}
          onSort={handleSort}
          onView={handleViewHistory}
          canView={canView}
          formatRecallAt={formatRecallAt}
          formatRecallFull={formatRecallFull}
          emptyText={emptyText}
        />
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
          {rows.map((r, i) => (
            <ReminderCard
              key={r.id}
              reminder={r}
              num={startIndex + i + 1}
              onView={handleViewHistory}
              canView={canView}
              formatRecallAt={formatRecallAt}
              formatRecallFull={formatRecallFull}
            />
          ))}
          {rows.length === 0 && (
            <div className="col-span-full rounded-xl bg-card text-card-foreground border border-border/60 shadow-sm h-40 flex flex-col items-center justify-center gap-2 text-center p-6">
              <span className="h-10 w-10 rounded-full bg-muted flex items-center justify-center">
                <BellOff className="h-5 w-5 text-muted-foreground" />
              </span>
              <p className="text-muted-foreground">{emptyText}</p>
            </div>
          )}
        </div>
      )}

      <Pagination
        currentPage={currentPage}
        totalPages={totalPages}
        rowsPerPage={rowsPerPage}
        onPageChange={setCurrentPage}
        onRowsPerPageChange={handleRowsPerPageChange}
      />
    </div>
  )
}
