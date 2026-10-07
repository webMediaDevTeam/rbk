import { useCommercialProspectList } from './useCommercialProspectList.js'
import ProspectTable from './components/ProspectTable.jsx'
import ProspectCard from './components/ProspectCard.jsx'
import ProspectToolbar from './components/ProspectToolbar.jsx'
import Pagination from '@/pages/shared/components/Pagination/index.jsx'
import ReservationModal from './components/ReservationModal.jsx'
import Button from '@/components/ui/button.jsx'

export default function ProspectListPage() {
  const {
    isDesktop,
    search, setSearch,
    municipality,
    categories,
    region,
    handleMunicipalityChange,
    handleCategoriesChange,
    handleRegionChange,
    currentPage, setCurrentPage,
    rowsPerPage, handleRowsPerPageChange,
    sortBy, sortOrder, handleSort,
    clients, isLoading,
    totalPages,
    showReserve, openReserve, closeReserve,
    pendingReservations, canReserve, isLoadingCounts,
    handleViewDetail,
    filters,
  } = useCommercialProspectList()

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <div className="flex items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Grande liste</h1>
          <p className="text-sm text-muted-foreground mt-1">Visualisez et gérez tous les prospects disponibles.</p>
        </div>
        <div className="flex items-center gap-3">
          {/* Garde de traitement : tant qu'il reste des réservations
              `PENDING` (prospects « en attente ») dans ses listes, le
              serveur refuse un nouveau lot — la raison est affichée et la
              modale propose le bouton « Libérer la liste ». */}
          {!canReserve && (
            <p className="text-xs text-muted-foreground text-right max-w-[17rem] leading-snug">
              {isLoadingCounts
                ? 'Vérification de vos listes en cours…'
                : typeof pendingReservations === 'number'
                  ? `${pendingReservations} prospect(s) à traiter dans vos listes : terminez-les ou libérez votre liste.`
                  : 'Vérification de vos listes en cours…'}
            </p>
          )}
          <Button
            variant="default"
            onClick={openReserve}
            disabled={isLoadingCounts}
            title={isLoadingCounts ? 'Vérification de vos listes en cours…' : undefined}
          >
            Réserver
          </Button>
        </div>
      </div>

      {/* Barre de badges de statut (ProspectKpis) supprimée à la demande :
          la liste commerciale ne contient que des prospects disponibles. */}

      <ProspectToolbar
        search={search} setSearch={setSearch}
        municipality={municipality} setMunicipality={handleMunicipalityChange}
        categories={categories} setCategories={handleCategoriesChange}
        region={region} setRegion={handleRegionChange}
      />

      {isLoading ? (
        <div className="h-48 flex items-center justify-center text-muted-foreground">Chargement...</div>
      ) : isDesktop ? (
        <ProspectTable
          clients={clients}
          startIndex={(currentPage - 1) * rowsPerPage}
          sortBy={sortBy} sortOrder={sortOrder} onSort={handleSort}
          onViewDetail={handleViewDetail}
        />
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
          {clients.map((c, i) => (
            <ProspectCard
              key={c.id}
              num={(currentPage - 1) * rowsPerPage + i + 1}
              client={c}
              onViewDetail={handleViewDetail}
            />
          ))}
        </div>
      )}

      <Pagination
        currentPage={currentPage}
        totalPages={totalPages}
        rowsPerPage={rowsPerPage}
        onPageChange={setCurrentPage}
        onRowsPerPageChange={handleRowsPerPageChange}
      />
      <ReservationModal
        open={showReserve}
        onClose={closeReserve}
        filters={filters}
        canReserve={canReserve}
        pendingReservations={pendingReservations}
      />
    </div>
  )
}