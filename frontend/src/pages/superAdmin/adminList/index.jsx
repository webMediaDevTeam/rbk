import { useState } from 'react'
import { ChevronRight, Home, Plus } from 'lucide-react'
import Button from '../../../components/ui/button.jsx'
import { toast } from 'sonner'
import { useDebouncedValue } from '../../../hooks/use-debounced-value.js'
import { useIsDesktop } from '../../../hooks/use-mobile.js'
import { useAdminList, useDeleteAdmin, useToggleAdminStatus } from './useAdminList.js'
import AdminTable from './components/AdminTable.jsx'
import AdminCard from './components/AdminCard.jsx'
import AdminToolbar from './components/AdminToolbar.jsx'
import AvatarUpdateModal from '../../shared/components/AvatarUpdateModal.jsx'
import AdminCreateModal from '../../shared/components/AdminCreateModal.jsx'
import AdminUpdateModal from '../../shared/components/AdminUpdateModal.jsx'
import Pagination from '../../shared/users/components/Pagination.jsx'

export default function AdminListPage() {
  const isDesktop = useIsDesktop()
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [currentPage, setCurrentPage] = useState(1)
  const [rowsPerPage, setRowsPerPage] = useState(10)
  const [sortBy, setSortBy] = useState('created_at')
  const [sortOrder, setSortOrder] = useState('desc')
  const [avatarTarget, setAvatarTarget] = useState(null)
  const [showCreate, setShowCreate] = useState(false)
  const [editTarget, setEditTarget] = useState(null)

  const debouncedSearch = useDebouncedValue(search, 400)
  const searchParam = debouncedSearch.trim().length >= 3 ? debouncedSearch.trim() : undefined

  const { data } = useAdminList({
    search: searchParam,
    status: statusFilter || undefined,
    page: currentPage,
    per_page: rowsPerPage,
    sort_by: sortBy,
    sort_order: sortOrder,
  })

  const deleteMut = useDeleteAdmin()
  const toggleMut = useToggleAdminStatus()

  const admins = data?.data?.utilisateurs ?? []
  const total = data?.data?.pagination?.total ?? 0

  const handleSort = (column) => {
    if (sortBy === column) {
      setSortOrder((prev) => (prev === 'asc' ? 'desc' : 'asc'))
    } else {
      setSortBy(column)
      setSortOrder('asc')
    }
  }

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <nav className="flex items-center gap-1.5 text-sm text-muted-foreground">
        <a href="#" onClick={(e) => e.preventDefault()} className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
          <Home className="h-3.5 w-3.5" /> Accueil
        </a>
        <ChevronRight className="h-3.5 w-3.5" />
        <span className="font-medium text-foreground">Admins</span>
      </nav>

      <div className="flex items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Liste des admins</h1>
          <p className="text-sm text-muted-foreground mt-1">Gérez vos admins et leurs accès ici.</p>
        </div>
        <Button variant="default" size="md" className="px-2.5 lg:px-4" onClick={() => setShowCreate(true)}>
          <Plus className="h-4 w-4" />
          <span className="hidden lg:inline">Ajouter un admin</span>
        </Button>
      </div>

      <AdminToolbar
        search={search} setSearch={setSearch}
        statusFilter={statusFilter} setStatusFilter={(s) => { setStatusFilter(s); setCurrentPage(1) }}
      />

      {isDesktop ? (
        <AdminTable
          admins={admins}
          sortBy={sortBy} sortOrder={sortOrder} onSort={handleSort}
          onToggleStatus={(id, status) => toggleMut.mutate({ id, status }, { onSuccess: () => toast.success('Statut mis à jour.') })}
          onDelete={(id) => deleteMut.mutate(id, { onSuccess: () => toast.success('Supprimé.') })}
          onAvatarClick={setAvatarTarget}
          onEdit={setEditTarget}
        />
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
          {admins.map((a) => (
            <AdminCard key={a.id} admin={a} onAvatarClick={setAvatarTarget} onEdit={setEditTarget} onToggleStatus={(id, status) => toggleMut.mutate({ id, status }, { onSuccess: () => toast.success('Statut mis à jour.') })} onDelete={(id) => deleteMut.mutate(id, { onSuccess: () => toast.success('Supprimé.') })} />
          ))}
        </div>
      )}

      <AvatarUpdateModal
        open={!!avatarTarget}
        user={avatarTarget}
        queryKey={['admins']}
        onClose={() => setAvatarTarget(null)}
      />

      <AdminCreateModal
        open={showCreate}
        queryKey={['admins']}
        onClose={() => setShowCreate(false)}
      />

      <AdminUpdateModal
        open={!!editTarget}
        user={editTarget}
        queryKey={['admins']}
        onClose={() => setEditTarget(null)}
      />

      <Pagination
        currentPage={currentPage}
        totalPages={Math.max(1, Math.ceil(total / rowsPerPage))}
        rowsPerPage={rowsPerPage}
        onPageChange={setCurrentPage}
        onRowsPerPageChange={(n) => { setRowsPerPage(n); setCurrentPage(1) }}
      />
    </div>
  )
}