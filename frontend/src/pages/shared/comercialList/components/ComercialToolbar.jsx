import SearchBar from '@/components/ui/search-bar.jsx'
import Select from '@/components/ui/select'

export default function ComercialToolbar({
  search,
  setSearch,
  statusFilter,
  setStatusFilter,
}) {
  return (
    <div className="flex w-full gap-3">
      <SearchBar
        className="flex-1 min-w-0"
        inputProps={{
          value: search,
          onChange: (e) => setSearch(e.target.value),
          placeholder: 'Rechercher commerciaux... (min 3 chars)',
          'aria-label': 'Rechercher commerciaux',
        }}
      />
      <Select
        value={statusFilter}
        onChange={(e) => setStatusFilter(e.target.value)}
        className="w-40 cursor-pointer shrink-0"
      >
        <option value="">Statut: Tous</option>
        <option value="ACTIVE">Active</option>
        <option value="INACTIVE">Inactive</option>
      </Select>
    </div>
  )
}