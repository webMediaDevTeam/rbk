import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react'

export default function SortHeader({ column, currentSortBy, sortOrder, onSort, children }) {
  const isActive = currentSortBy === column

  return (
    <button
      onClick={() => onSort(column)}
      className="flex items-center gap-1 cursor-pointer font-semibold hover:text-foreground transition-colors"
    >
      {children}
      {isActive
        ? (sortOrder === 'asc' ? <ArrowUp className="h-3.5 w-3.5" /> : <ArrowDown className="h-3.5 w-3.5" />)
        : <ArrowUpDown className="h-3.5 w-3.5 text-muted-foreground" />}
    </button>
  )
}