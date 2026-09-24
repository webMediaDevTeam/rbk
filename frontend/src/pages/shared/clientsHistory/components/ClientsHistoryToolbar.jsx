import SearchBar from '@/components/ui/search-bar.jsx'
import Select from '@/components/ui/select'
import { useClientsHistoryToolbar } from './useClientsHistoryToolbar.js'

export default function ClientsHistoryToolbar(props) {
  const { searchInputProps, statusProps } = useClientsHistoryToolbar(props)

  return (
    <div className="flex w-full flex-wrap gap-3">
      <SearchBar
        className="flex-1 min-w-0"
        inputProps={searchInputProps}
      />
      <Select
        {...statusProps}
        className="w-56 cursor-pointer shrink-0"
      >
        <option value="">Statut: Tous</option>
        <option value="AVAILABLE">Disponible</option>
        <option value="RESERVED">Réservé</option>
        <option value="SUCCESS">Confirmé</option>
        <option value="UNAVAILABLE_TEMP">Indisponible</option>
        <option value="BLACKLISTED">Liste noire</option>
      </Select>
      {/* Filtres de date « Du / Au » supprimés : la recherche ne filtre plus sur created_at. */}
    </div>
  )
}