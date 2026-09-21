import SearchBar from '@/components/ui/search-bar.jsx'
import Select from '@/components/ui/select'
import { useClientsHistoryToolbar } from './useClientsHistoryToolbar.js'

export default function ClientsHistoryToolbar(props) {
  const { searchInputProps, statusProps } = useClientsHistoryToolbar(props)

  return (
    <div className="flex w-full gap-3">
      <SearchBar
        className="flex-1 min-w-0"
        inputProps={searchInputProps}
      />
      <Select
        {...statusProps}
        className="w-64 cursor-pointer shrink-0"
      >
        <option value="">Statut: Tous</option>
        <option value="AVAILABLE">Disponible</option>
        <option value="RESERVED">Réservé</option>
        <option value="VOICEMAIL">Boîte vocale</option>
        <option value="INJOINABLE">Injoignable</option>
        <option value="BLACKLISTED">Liste noire</option>
      </Select>
    </div>
  )
}