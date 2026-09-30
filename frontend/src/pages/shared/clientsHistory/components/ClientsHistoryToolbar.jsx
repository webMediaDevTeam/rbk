import SearchBar from '@/components/ui/search-bar.jsx'
import Select from '@/components/ui/select'
import { useClientsHistoryToolbar } from './useClientsHistoryToolbar.js'
import { useFilterOptions } from '@/hooks/use-filter-options.js'

export default function ClientsHistoryToolbar(props) {
  // Le filtre « Statut » (menu déroulant) a été supprimé : ce sont les
  // badges de statut de l'overview, au-dessus, qui filtrent — en
  // sélection unique. Le composant ne pilote donc plus que la recherche
  // et les filtres géographiques / catégorie.
  const { searchInputProps } = useClientsHistoryToolbar(props)
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
