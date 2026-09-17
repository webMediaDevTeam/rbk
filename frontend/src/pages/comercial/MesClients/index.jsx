import { useState } from 'react'
import { ChevronRight, Home } from 'lucide-react'
import ClientTable from '../ClientList/components/ClientTable.jsx'
import Pagination from '../../shared/users/components/Pagination.jsx'
import { api } from '../../../api/client.js'
import { useQuery } from '@tanstack/react-query'

export default function MesClientsPage() {
  const [currentPage, setCurrentPage] = useState(1)
  const [rowsPerPage, setRowsPerPage] = useState(10)

  const { data, isLoading } = useQuery({
    queryKey: ['mes-clients', currentPage, rowsPerPage],
    queryFn: () => api.get('/clients/mes', { params: { page: currentPage, per_page: rowsPerPage } }),
  })

  const clients = data?.data?.clients ?? []
  const total = data?.data?.pagination?.total ?? 0

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
      ) : (
        <ClientTable clients={clients} />
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
