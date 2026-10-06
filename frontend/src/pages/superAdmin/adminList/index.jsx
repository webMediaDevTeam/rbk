import { Plus } from 'lucide-react'
import Button from '@/components/ui/button.jsx'
import { useAdminListPage } from './useAdminList.js'
import AdminTable from './components/AdminTable.jsx'
import AdminCard from './components/AdminCard.jsx'
import AdminToolbar from './components/AdminToolbar.jsx'
import AvatarUpdateModal from '@/pages/shared/components/AvatarUpdateModal/index.jsx'
import AdminCreateModal from '@/pages/shared/components/AdminCreateModal/index.jsx'
import AdminUpdateModal from '@/pages/shared/components/AdminUpdateModal/index.jsx'
import Pagination from '@/pages/shared/components/Pagination/index.jsx'

export default function AdminListPage() {
  const {
    isDesktop,
    search,
    setSearch,
    statusFilter,
    handleStatusFilterChange,
    currentPage,
    rowsPerPage,
    sortBy,
    sortOrder,
    admins,
    totalPages,
    avatarTarget,
    showCreate,
    editTarget,
    handleSort,
    handleToggleStatus,
    handleDelete,
    handleAvatarClick,
    handleEdit,
    handleOpenCreate,
    handleCloseAvatarModal,
    handleCloseCreate,
    handleCloseEdit,
    handlePageChange,
    handleRowsPerPageChange,
  } = useAdminListPage()

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <div className="flex items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Liste des admins</h1>
          <p className="text-sm text-muted-foreground mt-1">Gérez vos admins et leurs accès ici.</p>
        </div>
        <Button variant="default" size="md" className="px-2.5 lg:px-4" onClick={handleOpenCreate}>
          <Plus className="h-4 w-4" />
          <span className="hidden lg:inline">Ajouter un admin</span>
        </Button>
      </div>

      <AdminToolbar
        search={search} setSearch={setSearch}
        statusFilter={statusFilter} setStatusFilter={handleStatusFilterChange}
      />

      {isDesktop ? (
        <AdminTable
          admins={admins}
          sortBy={sortBy} sortOrder={sortOrder} onSort={handleSort}
          onToggleStatus={handleToggleStatus}
          onDelete={handleDelete}
          onAvatarClick={handleAvatarClick}
          onEdit={handleEdit}
        />
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
          {admins.map((a) => (
            <AdminCard key={a.id} admin={a} onAvatarClick={handleAvatarClick} onEdit={handleEdit} onToggleStatus={handleToggleStatus} onDelete={handleDelete} />
          ))}
        </div>
      )}

      <AvatarUpdateModal
        open={!!avatarTarget}
        user={avatarTarget}
        queryKey={['admins']}
        onClose={handleCloseAvatarModal}
      />

      <AdminCreateModal
        open={showCreate}
        queryKey={['admins']}
        onClose={handleCloseCreate}
      />

      <AdminUpdateModal
        open={!!editTarget}
        user={editTarget}
        queryKey={['admins']}
        onClose={handleCloseEdit}
      />

      <Pagination
        currentPage={currentPage}
        totalPages={totalPages}
        rowsPerPage={rowsPerPage}
        onPageChange={handlePageChange}
        onRowsPerPageChange={handleRowsPerPageChange}
      />
    </div>
  )
}