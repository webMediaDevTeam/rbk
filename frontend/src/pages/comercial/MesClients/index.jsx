import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ChevronRight, Home } from 'lucide-react'
import { useIsDesktop } from '@/hooks/use-mobile.js'
import ClientTable from '@/pages/comercial/ClientList/components/ClientTable.jsx'
import ClientCard from '@/pages/comercial/ClientList/components/ClientCard.jsx'
import Pagination from '@/pages/shared/users/components/Pagination.jsx'
import { api } from '@/api/client.js'
import { useQuery } from '@tanstack/react-query'

export default function MesClientsPage() {
  const navigate = useNavigate()
  const isDesktop = useIsDesktop()
  const [currentPage, setCurrentPage] = useState(1)
  const [rowsPerPage, setRowsPerPage] = useState(10)
  const [sortBy, setSortBy] = useState('created_at')
  const [sortOrder, setSortOrder] = useState('desc')

  const { data, isLoading } = useQuery({
    queryKey: ['mes-clients', currentPage, rowsPerPage],
    queryFn: () => api.get('/clients/mes', { params: { page: currentPage, per_page: rowsPerPage } }),
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

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <nav className="flex items-center gap-1.5 text-sm text-muted-foreground">
        <a href="#" onClick={(e) => e.preventDefault()} className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
          <Home className="h-3.5 w-3.5" /> Accueil
        </a>
        <ChevronRight className="h-3.5 w-3.5" />
        <span className="font-medium text-foreground">Mes clients</span>
      </nav>

      <div>
        <h1 className="text-2xl font-bold tracking-tight text-foreground">Mes clients</h1>
        <p className="text-sm text-muted-foreground mt-1">Clients actuellement réservés par vous.</p>
      </div>

      {isLoading ? (
        <div className="h-48 flex items-center justify-center text-muted-foreground">Chargement...</div>
      ) : isDesktop ? (
        <ClientTable
          clients={clients}
          sortBy={sortBy} sortOrder={sortOrder} onSort={handleSort}
          onViewDetail={(c) => navigate(`/mes-clients/${c.id}`)}
        />
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
          {clients.map((c) => (
            <ClientCard key={c.id} client={c} onViewDetail={(cl) => navigate(`/mes-clients/${cl.id}`)} />
          ))}
        </div>
      )}

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
