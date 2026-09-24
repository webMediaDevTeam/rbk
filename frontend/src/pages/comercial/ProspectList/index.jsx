import { ChevronRight, Home } from 'lucide-react'
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
    categories,
    handleCategoriesChange,
    currentPage, setCurrentPage,
    rowsPerPage, handleRowsPerPageChange,
    sortBy, sortOrder, handleSort,
    clients, isLoading,
    total, totalPages,
    showReserve, openReserve, closeReserve,
    handleViewDetail,
  } = useCommercialProspectList()

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <nav className="flex items-center gap-1.5 text-sm text-muted-foreground">
        <a href="#" onClick={(e) => e.preventDefault()} className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
          <Home className="h-3.5 w-3.5" /> Accueil
        </a>
        <ChevronRight className="h-3.5 w-3.5" />
        <span className="font-medium text-foreground">Prospects</span>
      </nav>

      <div className="flex items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Liste de tous les prospects</h1>
          <p className="text-sm text-muted-foreground mt-1">Visualisez et gérez tous les prospects disponibles.</p>
        </div>
        <div>
          <Button variant="default" onClick={openReserve}>Réserver</Button>
        </div>
      </div>

      <ProspectToolbar
        search={search} setSearch={setSearch}
        categories={categories} setCategories={handleCategoriesChange}
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
      <ReservationModal open={showReserve} onClose={closeReserve} />
    </div>
  )
}