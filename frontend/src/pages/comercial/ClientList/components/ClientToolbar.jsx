import { useEffect, useState } from 'react'
import SearchBar from '@/components/ui/search-bar.jsx'
import Select from '@/components/ui/select'
import { api } from '@/api/client.js'

export default function ClientToolbar({
  search,
  setSearch,
  categories,
  setCategories,
}) {
  const [categoriesList, setCategoriesList] = useState([])

  useEffect(() => {
    let mounted = true
    api.get('/categories')
      .then((res) => {
        if (!mounted) return
        setCategoriesList(res.data?.categories || [])
      })
      .catch(() => {
        // ignore failures; keep list empty
      })
    return () => { mounted = false }
  }, [])
  return (
    <div className="flex w-full gap-3">
      <SearchBar
        className="flex-1 min-w-0"
        inputProps={{
          value: search,
          onChange: (e) => setSearch(e.target.value),
          placeholder: 'Rechercher clients... (min 3 chars)',
          'aria-label': 'Rechercher clients',
        }}
      />
      {/* status filter removed - server enforces AVAILABLE for commercial listing */}
      {/* blacklist filtering removed; server returns only non-blacklisted, available clients */}
      <Select
        value={categories}
        onChange={(e) => setCategories(e.target.value)}
        className="w-64 cursor-pointer shrink-0"
      >
        <option value="">Catégorie: Toutes</option>
        {Array.isArray(categoriesList) && categoriesList.map((c) => (
          <option key={c.id} value={c.id}>{c.label}</option>
        ))}
      </Select>
    </div>
  )
}