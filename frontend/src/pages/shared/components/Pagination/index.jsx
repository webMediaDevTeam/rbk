import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from 'lucide-react'
import Button from '@/components/ui/button'
import Select from '@/components/ui/select'

export default function Pagination({
  currentPage,
  totalPages,
  rowsPerPage,
  onPageChange,
  onRowsPerPageChange,
}) {
  if (totalPages <= 1) return null

  return (
    <div className="flex flex-col sm:flex-row items-center justify-between gap-4 pt-2">
      <div className="flex items-center gap-3 text-sm text-foreground">
        <div className="relative">
          <Select
            value={rowsPerPage}
            onChange={(e) => onRowsPerPageChange(Number(e.target.value))}
            className="w-auto pr-8 appearance-none cursor-pointer"
          >
            <option value={50}>50</option>
            <option value={100}>100</option>
            <option value={200}>200</option>
            <option value={300}>300</option>
          </Select>
        </div>
        <span className="font-bold">Lignes par page</span>
      </div>
      <div className="flex items-center gap-6 text-sm text-foreground">
        <span className="font-medium">
          Page {currentPage} sur {totalPages}
        </span>
        <div className="flex items-center gap-1.5">
          <Button variant="secondary" size="icon-sm" disabled={currentPage === 1} onClick={() => onPageChange(1)}>
            <ChevronsLeft className="h-4 w-4" />
          </Button>
          <Button variant="secondary" size="icon-sm" disabled={currentPage === 1} onClick={() => onPageChange(currentPage - 1)}>
            <ChevronLeft className="h-4 w-4" />
          </Button>
          {[1, 2, 3, 4].map((page) => (
            <Button
              key={page}
              variant={currentPage === page ? 'default' : 'secondary'}
              size="icon-sm"
              onClick={() => onPageChange(page)}
            >
              {page}
            </Button>
          ))}
          <span className="px-1 text-muted-foreground">...</span>
          <Button
            variant={currentPage === totalPages ? 'default' : 'secondary'}
            size="icon-sm"
            onClick={() => onPageChange(totalPages)}
          >
            {totalPages}
          </Button>
          <Button variant="secondary" size="icon-sm" disabled={currentPage === totalPages} onClick={() => onPageChange(currentPage + 1)}>
            <ChevronRight className="h-4 w-4" />
          </Button>
          <Button variant="secondary" size="icon-sm" disabled={currentPage === totalPages} onClick={() => onPageChange(totalPages)}>
            <ChevronsRight className="h-4 w-4" />
          </Button>
        </div>
      </div>
    </div>
  )
}