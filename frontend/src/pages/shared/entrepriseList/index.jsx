import { ChevronRight, Home, Plus } from 'lucide-react'
import Button from '@/components/ui/button.jsx'
import { useEntrepriseListPage } from './useEntrepriseList.js'
import EntrepriseTable from './components/EntrepriseTable.jsx'
import EntrepriseCard from './components/EntrepriseCard.jsx'
import EntrepriseToolbar from './components/EntrepriseToolbar.jsx'
import AvatarUpdateModal from '@/pages/shared/components/AvatarUpdateModal.jsx'
import EnterpriseCreateModal from '@/pages/shared/components/EnterpriseCreateModal.jsx'
import EnterpriseUpdateModal from '@/pages/shared/components/EnterpriseUpdateModal.jsx'
import Pagination from '@/pages/shared/users/components/Pagination.jsx'

export default function EntrepriseListPage() {
  const {
    isDesktop,
    isLoading,
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
    canDelete,
    entreprises,
    sortBy,
    sortOrder,
    handleSort,
    handleToggleStatus,
    handleDelete,
    avatarTarget,
    setAvatarTarget,
    handleCloseAvatar,
    handleLogoUpload,
    showCreate,
    handleOpenCreate,
    handleCloseCreate,
    editTarget,
    setEditTarget,
    handleCloseEdit,
    handleHomeClick,
  } = useEntrepriseListPage()

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <nav className="flex items-center gap-1.5 text-sm text-muted-foreground">
        <a href="#" onClick={handleHomeClick} className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
          <Home className="h-3.5 w-3.5" /> Accueil
        </a>
        <ChevronRight className="h-3.5 w-3.5" />
        <span className="font-medium text-foreground">Entreprises</span>
      </nav>

      <div className="flex items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Liste des entreprises</h1>
          <p className="text-sm text-muted-foreground mt-1">Gérez vos entreprises et leurs comptes ici.</p>
        </div>
        {canCreate && (
          <Button variant="default" size="md" className="px-2.5 lg:px-4" onClick={handleOpenCreate}>
            <Plus className="h-4 w-4" />
            <span className="hidden lg:inline">Ajouter une entreprise</span>
          </Button>
        )}
      </div>

      <EntrepriseToolbar
        search={search} setSearch={setSearch}
        statusFilter={statusFilter} setStatusFilter={handleStatusFilterChange}
      />

      {isDesktop ? (
        <EntrepriseTable
          entreprises={entreprises}
          sortBy={sortBy} sortOrder={sortOrder} onSort={handleSort}
          canDelete={canDelete}
          onToggleStatus={handleToggleStatus}
          onDelete={handleDelete}
          onAvatarClick={setAvatarTarget}
          onEdit={setEditTarget}
        />
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
          {entreprises.map((e) => (
            <EntrepriseCard key={e.id} entreprise={e} onAvatarClick={setAvatarTarget} onEdit={setEditTarget} onToggleStatus={handleToggleStatus} onDelete={handleDelete} canDelete={canDelete} />
          ))}
          {isLoading && <div className="text-muted-foreground text-sm col-span-full text-center py-8">Chargement...</div>}
        </div>
      )}

      <AvatarUpdateModal
        open={!!avatarTarget}
        user={avatarTarget}
        title="Logo de l'entreprise"
        logoOnly
        uploadFn={handleLogoUpload}
        queryKey={['entreprises']}
        onClose={handleCloseAvatar}
      />

      <EnterpriseCreateModal
        open={showCreate}
        queryKey={['entreprises']}
        onClose={handleCloseCreate}
      />

      <EnterpriseUpdateModal
        open={!!editTarget}
        user={editTarget}
        queryKey={['entreprises']}
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