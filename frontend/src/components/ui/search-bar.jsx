import { Search } from 'lucide-react'
import { cn } from '@/lib/utils'

export default function SearchBar({ className, inputProps }) {
  return (
    <div className={cn('relative w-full', className)}>
      <Search className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
      <input
        {...inputProps}
        className="flex h-9 w-full rounded-lg border border-input bg-card pl-9 pr-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:border-ring disabled:cursor-not-allowed disabled:opacity-50"
      />
    </div>
  )
}