import { useState } from 'react'
import { ChevronRight, Home, Plus } from 'lucide-react'
import Button from '../../../components/ui/button.jsx'
import { toast } from 'sonner'
import { useAuth } from '../../../context/AuthContext.jsx'
import { useDebouncedValue } from '../../../hooks/use-debounced-value.js'
import { useIsDesktop } from '../../../hooks/use-mobile.js'
import { useComercialList, useDeleteCommercial, useToggleCommercialStatus } from './useComercialList.js'
import ComercialTable from './components/ComercialTable.jsx'
import ComercialCard from './components/ComercialCard.jsx'
import ComercialToolbar from './components/ComercialToolbar.jsx'
import AvatarUpdateModal from '../../../pages/shared/components/AvatarUpdateModal.jsx'
import CommercialCreateModal from '../../../pages/shared/components/CommercialCreateModal.jsx'
import CommercialUpdateModal from '../../../pages/shared/components/CommercialUpdateModal.jsx'
import Pagination from '../users/components/Pagination.jsx'

export default function ComercialListPage() {
  const { canAccess } = useAuth()
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

  const { data } = useComercialList({
    search: searchParam,
    status: statusFilter || undefined,
    page: currentPage,
    per_page: rowsPerPage,
    sort_by: sortBy,
    sort_order: sortOrder,
  })

  const deleteMut = useDeleteCommercial()
  const toggleMut = useToggleCommercialStatus()

  const commerciaux = data?.data?.utilisateurs ?? []
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
        <span className="font-medium text-foreground">Commerciaux</span>
      </nav>

      <div className="flex items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Liste des commerciaux</h1>
          <p className="text-sm text-muted-foreground mt-1">Gérez vos commerciaux et leurs performances ici.</p>
        </div>
        {canAccess('commercials:create') && (
          <Button variant="default" size="md" className="px-2.5 lg:px-4" onClick={() => setShowCreate(true)}>
            <Plus className="h-4 w-4" />
            <span className="hidden lg:inline">Ajouter un commercial</span>
          </Button>
        )}
      </div>

      <ComercialToolbar
        search={search} setSearch={setSearch}
        statusFilter={statusFilter} setStatusFilter={(s) => { setStatusFilter(s); setCurrentPage(1) }}
      />
  
      {isDesktop ? (
        <ComercialTable
          commerciaux={commerciaux}
          sortBy={sortBy} sortOrder={sortOrder} onSort={handleSort}
          canDelete={canAccess('commercials:update')}
          onToggleStatus={(id, status) => toggleMut.mutate({ id, status }, { onSuccess: () => toast.success('Statut mis à jour.') })}
          onDelete={(id) => deleteMut.mutate(id, { onSuccess: () => toast.success('Supprimé.') })}
          onAvatarClick={setAvatarTarget}
          onEdit={setEditTarget}
        />
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
          {commerciaux.map((c) => (
            <ComercialCard key={c.id} comercial={c} onAvatarClick={setAvatarTarget} onEdit={setEditTarget} onToggleStatus={(id, status) => toggleMut.mutate({ id, status }, { onSuccess: () => toast.success('Statut mis à jour.') })} onDelete={(id) => deleteMut.mutate(id, { onSuccess: () => toast.success('Supprimé.') })} canDelete={canAccess('commercials:update')} />
          ))}
        </div>
      )}

      <AvatarUpdateModal
        open={!!avatarTarget}
        user={avatarTarget}
        queryKey={['commerciaux']}
        onClose={() => setAvatarTarget(null)}
      />

      <CommercialCreateModal
        open={showCreate}
        queryKey={['commerciaux']}
        onClose={() => setShowCreate(false)}
      />

      <CommercialUpdateModal
        open={!!editTarget}
        user={editTarget}
        queryKey={['commerciaux']}
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