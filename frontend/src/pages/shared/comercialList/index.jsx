import { ChevronRight, Home, Plus } from 'lucide-react'
import Button from '@/components/ui/button.jsx'
import { useComercialListPage } from './useComercialList.js'
import ComercialTable from './components/ComercialTable.jsx'
import ComercialCard from './components/ComercialCard.jsx'
import ComercialToolbar from './components/ComercialToolbar.jsx'
import AvatarUpdateModal from '@/pages/shared/components/AvatarUpdateModal/index.jsx'
import CommercialCreateModal from '@/pages/shared/components/CommercialCreateModal/index.jsx'
import CommercialUpdateModal from '@/pages/shared/components/CommercialUpdateModal/index.jsx'
import Pagination from '@/pages/shared/components/Pagination/index.jsx'

export default function ComercialListPage() {
  const {
    isDesktop,
    search,
    setSearch,
    statusFilter,
    handleStatusFilterChange,
    currentPage,
    setCurrentPage,
    rowsPerPage,
    handleRowsPerPageChange,
    totalPages,
    canCreate,
    canUpdate,
    commerciaux,
    sortBy,
    sortOrder,
    handleSort,
    handleToggleStatus,
    handleDelete,
    avatarTarget,
    setAvatarTarget,
    handleCloseAvatar,
    showCreate,
    handleOpenCreate,
    handleCloseCreate,
    editTarget,
    setEditTarget,
    handleCloseEdit,
    handleHomeClick,
  } = useComercialListPage()

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <nav className="flex items-center gap-1.5 text-sm text-muted-foreground">
        <a href="#" onClick={handleHomeClick} className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
          <Home className="h-3.5 w-3.5" /> Accueil
        </a>
        <ChevronRight className="h-3.5 w-3.5" />
        <span className="font-medium text-foreground">Commerciaux</span>
      </nav>

      <div className="flex items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Liste des commerciaux</h1>
          <p className="text-sm text-muted-foreground mt-1">Gérez vos commerciaux et leurs performances ici.</p>
        </div>
        {canCreate && (
          <Button variant="default" size="md" className="px-2.5 lg:px-4" onClick={handleOpenCreate}>
            <Plus className="h-4 w-4" />
            <span className="hidden lg:inline">Ajouter un commercial</span>
          </Button>
        )}
      </div>

      <ComercialToolbar
        search={search} setSearch={setSearch}
        statusFilter={statusFilter} setStatusFilter={handleStatusFilterChange}
      />

      {isDesktop ? (
        <ComercialTable
          commerciaux={commerciaux}
          sortBy={sortBy} sortOrder={sortOrder} onSort={handleSort}
          canDelete={canUpdate}
          onToggleStatus={handleToggleStatus}
          onDelete={handleDelete}
          onAvatarClick={setAvatarTarget}
          onEdit={setEditTarget}
        />
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
          {commerciaux.map((c) => (
            <ComercialCard key={c.id} comercial={c} onAvatarClick={setAvatarTarget} onEdit={setEditTarget} onToggleStatus={handleToggleStatus} onDelete={handleDelete} canDelete={canUpdate} />
          ))}
        </div>
      )}

      <AvatarUpdateModal
        open={!!avatarTarget}
        user={avatarTarget}
        queryKey={['commerciaux']}
        onClose={handleCloseAvatar}
      />

      <CommercialCreateModal
        open={showCreate}
        queryKey={['commerciaux']}
        onClose={handleCloseCreate}
      />

      <CommercialUpdateModal
        open={!!editTarget}
        user={editTarget}
        queryKey={['commerciaux']}
        onClose={handleCloseEdit}
      />

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