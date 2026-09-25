import { ChevronRight, Home } from 'lucide-react'
import { useClientsHistoryPage } from './useClientsHistory.js'
import ProspectTable from '@/pages/comercial/ProspectList/components/ProspectTable.jsx'
import ProspectCard from '@/pages/comercial/ProspectList/components/ProspectCard.jsx'
import ClientsHistoryToolbar from './components/ClientsHistoryToolbar.jsx'
import Pagination from '@/pages/shared/components/Pagination/index.jsx'
import ProspectKpis from '@/pages/shared/components/ProspectKpis/index.jsx'

export default function ClientsHistoryPage() {
  const {
    isDesktop,
    isLoading,
    search,
    handleSearchChange,
    status,
    handleStatusChange,
    municipality,
    handleMunicipalityChange,
    categories,
    handleCategoryChange,
    region,
    handleRegionChange,
    currentPage,
    setCurrentPage,
    rowsPerPage,
    handleRowsPerPageChange,
    totalPages,
    clients,
    sortBy,
    sortOrder,
    handleSort,
    handleViewDetail,
    handleHomeClick,
  } = useClientsHistoryPage()

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <nav className="flex items-center gap-1.5 text-sm text-muted-foreground">
        <a href="#" onClick={handleHomeClick} className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
          <Home className="h-3.5 w-3.5" /> Accueil
        </a>
        <ChevronRight className="h-3.5 w-3.5" />
        <span className="font-medium text-foreground">Prospect list</span>
      </nav>

      <div className="flex items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Prospect list</h1>
          <p className="text-sm text-muted-foreground mt-1">Tous les clients ayant déjà été contactés par un employé.</p>
        </div>
      </div>

      <ProspectKpis />

      <ClientsHistoryToolbar
        search={search} setSearch={handleSearchChange}
        status={status} setStatus={handleStatusChange}
        municipality={municipality} setMunicipality={handleMunicipalityChange}
        categories={categories} setCategories={handleCategoryChange}
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
    </div>
  )
}