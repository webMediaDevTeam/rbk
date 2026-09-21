export function useComercialToolbar({ search, setSearch, statusFilter, setStatusFilter }) {
  return {
    searchInputProps: {
      value: search,
      onChange: (e) => setSearch(e.target.value),
      placeholder: 'Rechercher commerciaux... (min 3 chars)',
      'aria-label': 'Rechercher commerciaux',
    },
    statusProps: {
      value: statusFilter,
      onChange: (e) => setStatusFilter(e.target.value),
    },
  }
}