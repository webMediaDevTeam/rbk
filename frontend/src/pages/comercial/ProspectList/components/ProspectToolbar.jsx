import SearchBar from '@/components/ui/search-bar.jsx'
import Select from '@/components/ui/select'
import { useProspectToolbar } from './useProspectToolbar.js'

export default function ProspectToolbar(props) {
  const {
    search, setSearch,
    municipality, setMunicipality,
    categories, setCategories,
    region, setRegion,
    municipalitiesList, categoriesList, regionsList,
  } = useProspectToolbar(props)

  return (
    <div className="flex w-full flex-wrap gap-3">
      <SearchBar
        className="flex-1 min-w-0"
        inputProps={{
          value: search,
          onChange: (e) => setSearch(e.target.value),
          placeholder: 'Rechercher prospects... (min 3 chars)',
          'aria-label': 'Rechercher prospects',
        }}
      />
      {/* status filter removed - server enforces AVAILABLE for commercial listing */}
      {/* blacklist filtering removed; server returns only non-blacklisted, available clients */}
      <Select
        value={municipality}
        onChange={(e) => setMunicipality(e.target.value)}
        className="w-56 cursor-pointer shrink-0"
      >
        <option value="">Municipalité: Toutes</option>
        {Array.isArray(municipalitiesList) && municipalitiesList.map((m) => (
          <option key={m} value={m}>{m}</option>
        ))}
      </Select>
      <Select
        value={categories}
        onChange={(e) => setCategories(e.target.value)}
        className="w-56 cursor-pointer shrink-0"
      >
        <option value="">Catégorie: Toutes</option>
        {Array.isArray(categoriesList) && categoriesList.map((c) => (
          <option key={c} value={c}>{c}</option>
        ))}
      </Select>
      <Select
        value={region}
        onChange={(e) => setRegion(e.target.value)}
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
