import SearchBar from '@/components/ui/search-bar.jsx'
import Select from '@/components/ui/select'
import { useComercialToolbar } from './useComercialToolbar.js'

export default function ComercialToolbar(props) {
  const { searchInputProps, statusProps } = useComercialToolbar(props)

  return (
    <div className="flex w-full gap-3">
      <SearchBar
        className="flex-1 min-w-0"
        inputProps={searchInputProps}
      />
      <Select
        {...statusProps}
        className="w-40 cursor-pointer shrink-0"
      >
        <option value="">Statut: Tous</option>
        <option value="ACTIVE">Active</option>
        <option value="INACTIVE">Inactive</option>
      </Select>
    </div>
  )
}