import SearchBar from '@/components/ui/search-bar.jsx'
import Select from '@/components/ui/select'
import { useClientsHistoryToolbar } from './useClientsHistoryToolbar.js'
import { useFilterOptions } from '@/hooks/use-filter-options.js'

export default function ClientsHistoryToolbar(props) {
  const { searchInputProps, statusProps } = useClientsHistoryToolbar(props)
  const { municipalitiesList, categoriesList, regionsList } = useFilterOptions()
  const {
    municipality, setMunicipality,
    categories, setCategories,
    region, setRegion,
  } = props

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
      <Select
        value={municipality ?? ''}
        onChange={(e) => setMunicipality?.(e.target.value)}
        className="w-56 cursor-pointer shrink-0"
      >
        <option value="">Municipalité: Toutes</option>
        {Array.isArray(municipalitiesList) && municipalitiesList.map((m) => (
          <option key={m} value={m}>{m}</option>
        ))}
      </Select>
      <Select
        value={categories ?? ''}
        onChange={(e) => setCategories?.(e.target.value)}
        className="w-56 cursor-pointer shrink-0"
      >
        <option value="">Catégorie: Toutes</option>
        {Array.isArray(categoriesList) && categoriesList.map((c) => (
          <option key={c} value={c}>{c}</option>
        ))}
      </Select>
      <Select
        value={region ?? ''}
        onChange={(e) => setRegion?.(e.target.value)}
        className="w-56 cursor-pointer shrink-0"
      >
        <option value="">Région: Toutes</option>
        {Array.isArray(regionsList) && regionsList.map((r) => (
          <option key={r} value={r}>{r}</option>
        ))}
      </Select>
      {/* Filtres de date « Du / Au » supprimés : la recherche ne filtre plus sur created_at. */}
    </div>
  )
}
