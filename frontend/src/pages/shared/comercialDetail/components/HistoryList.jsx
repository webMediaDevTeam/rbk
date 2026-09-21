import Pagination from '@/pages/shared/users/components/Pagination.jsx'
import HistoryTable from './HistoryTable.jsx'
import HistoryCard from './HistoryCard.jsx'
import { useHistoryList } from './useHistoryList.js'

export default function HistoryList(props) {
  const { clients, isDesktop, totalPages, currentPage, rowsPerPage, onPageChange, onRowsPerPageChange, onViewDetail } = useHistoryList(props)

  return (
    <div className="space-y-6">
      {isDesktop ? (
        <HistoryTable clients={clients} onViewDetail={onViewDetail} />
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
          {clients.map((c) => <HistoryCard key={c.id} client={c} onViewDetail={onViewDetail} />)}
        </div>
      )}

      <Pagination
        currentPage={currentPage}
        totalPages={totalPages}
        rowsPerPage={rowsPerPage}
        onPageChange={onPageChange}
        onRowsPerPageChange={onRowsPerPageChange}
      />
    </div>
  )
}