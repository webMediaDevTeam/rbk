import { ChevronRight, Home } from 'lucide-react'
import { useClientsHistoryPage } from './useClientsHistory.js'
import ProspectTable from '@/pages/comercial/ProspectList/components/ProspectTable.jsx'
import ProspectCard from '@/pages/comercial/ProspectList/components/ProspectCard.jsx'
import ClientsHistoryToolbar from './components/ClientsHistoryToolbar.jsx'
import Pagination from '@/pages/shared/users/components/Pagination.jsx'

export default function ClientsHistoryPage() {
  const {
    isDesktop,
    isLoading,
    search,
    handleSearchChange,
    status,
    handleStatusChange,
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
          <p className="text-sm text-muted-foreground mt-1">Tous les clients ayant déjà été contactés par un commercial.</p>
        </div>
      </div>

      <ClientsHistoryToolbar
        search={search} setSearch={handleSearchChange}
        status={status} setStatus={handleStatusChange}
      />

      {isLoading ? (
        <div className="h-48 flex items-center justify-center text-muted-foreground">Chargement...</div>
      ) : isDesktop ? (
        <ProspectTable
          clients={clients}
          sortBy={sortBy} sortOrder={sortOrder} onSort={handleSort}
          onViewDetail={handleViewDetail}
        />
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
          {clients.map((c) => (
            <ProspectCard key={c.id} client={c} onViewDetail={handleViewDetail} />
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