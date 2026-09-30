import SearchBar from '@/components/ui/search-bar.jsx'
import Select from '@/components/ui/select'
import { useFilterOptions } from '@/hooks/use-filter-options.js'

/**
 * Barre d'outils des listes de rappels — même trame que
 * `ProspectToolbar` : recherche + municipalité (filtrage côté client,
 * la liste est déjà entièrement chargée).
 */
export default function ReminderToolbar({ search, setSearch, municipality, setMunicipality }) {
  const { municipalitiesList } = useFilterOptions()

  return (
    <div className="flex w-full flex-wrap gap-3">
      <SearchBar
        className="flex-1 min-w-0"
        inputProps={{
          value: search,
          onChange: (e) => setSearch(e.target.value),
          placeholder: 'Rechercher rappels... (min 3 chars)',
          'aria-label': 'Rechercher rappels',
        }}
      />
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
    </div>
  )
}
