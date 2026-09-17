import { useState } from 'react'
import { ChevronRight, Home } from 'lucide-react'
import { useAuth } from '../../../context/AuthContext.jsx'
import { useDebouncedValue } from '../../../hooks/use-debounced-value.js'
import { useIsDesktop } from '../../../hooks/use-mobile.js'
import { useCommercialClientList } from './useCommercialClientList.js'
import ClientTable from './components/ClientTable.jsx'
import ClientToolbar from './components/ClientToolbar.jsx'
import Pagination from '../../shared/users/components/Pagination.jsx'
import ReservationModal from '../fileDAttente/components/ReservationModal.jsx'
import Button from '../../../components/ui/button.jsx'

export default function ClientListPage() {
  const { canAccess } = useAuth()
  const isDesktop = useIsDesktop()
  const [search, setSearch] = useState('')
  const [categories, setCategories] = useState('')
  const [currentPage, setCurrentPage] = useState(1)
  const [rowsPerPage, setRowsPerPage] = useState(10)
  const [sortBy, setSortBy] = useState('created_at')
  const [sortOrder, setSortOrder] = useState('desc')

  const debouncedSearch = useDebouncedValue(search, 400)
  const searchParam = debouncedSearch.trim().length >= 3 ? debouncedSearch.trim() : undefined

  const { data, isLoading } = useCommercialClientList({
    search: searchParam,
    category_id: categories || undefined,
    page: currentPage,
    per_page: rowsPerPage,
    sort_by: sortBy,
    sort_order: sortOrder,
  })

  const clients = data?.data?.clients ?? []
  const total = data?.data?.pagination?.total ?? 0

  const handleSort = (column) => {
    if (sortBy === column) {
      setSortOrder((prev) => (prev === 'asc' ? 'desc' : 'asc'))
    } else {
      setSortBy(column)
      setSortOrder('asc')
    }
  }

  const [showReserve, setShowReserve] = useState(false)

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <nav className="flex items-center gap-1.5 text-sm text-muted-foreground">
        <a href="#" onClick={(e) => e.preventDefault()} className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
          <Home className="h-3.5 w-3.5" /> Accueil
        </a>
        <ChevronRight className="h-3.5 w-3.5" />
        <span className="font-medium text-foreground">Mes clients</span>
      </nav>

      <div className="flex items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Liste de tous les clients</h1>
          <p className="text-sm text-muted-foreground mt-1">Visualisez et gérez tous les clients disponibles.</p>
        </div>
        <div>
          <Button variant="default" onClick={() => setShowReserve(true)}>Réserver</Button>
        </div>
      </div>

      <ClientToolbar
        search={search} setSearch={setSearch}
        categories={categories} setCategories={(c) => { setCategories(c); setCurrentPage(1) }}
      />

      {isLoading ? (
        <div className="h-48 flex items-center justify-center text-muted-foreground">Chargement...</div>
      ) : (
        <ClientTable
          clients={clients}
          sortBy={sortBy} sortOrder={sortOrder} onSort={handleSort}
        />
      )}

      <Pagination
        currentPage={currentPage}
        totalPages={Math.max(1, Math.ceil(total / rowsPerPage))}
        rowsPerPage={rowsPerPage}
        onPageChange={setCurrentPage}
        onRowsPerPageChange={(n) => { setRowsPerPage(n); setCurrentPage(1) }}
      />
      <ReservationModal open={showReserve} onClose={() => setShowReserve(false)} />
    </div>
  )
}