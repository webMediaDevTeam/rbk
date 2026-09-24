import SearchBar from '@/components/ui/search-bar.jsx'
import Select from '@/components/ui/select'
import { useProspectToolbar } from './useProspectToolbar.js'

export default function ProspectToolbar(props) {
  const { search, setSearch, categories, setCategories, categoriesList } = useProspectToolbar(props)

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
        value={categories}
        onChange={(e) => setCategories(e.target.value)}
        className="w-56 cursor-pointer shrink-0"
      >
        <option value="">Catégorie: Toutes</option>
        {Array.isArray(categoriesList) && categoriesList.map((c) => (
          <option key={c.id} value={c.id}>{c.label}</option>
        ))}
      </Select>
      {/* Filtres de date « Du / Au » supprimés : la recherche ne filtre plus sur created_at. */}
    </div>
  )
}