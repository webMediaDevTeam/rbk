import SearchBar from '@/components/ui/search-bar.jsx'
import { useHistoryToolbar } from './useHistoryToolbar.js'

export default function HistoryToolbar(props) {
  const { searchInputProps } = useHistoryToolbar(props)

  return (
    <div className="flex w-full gap-3">
      <SearchBar
        className="flex-1 min-w-0"
        inputProps={searchInputProps}
      />
    </div>
  )
}