export function useEntrepriseToolbar({ search, setSearch, statusFilter, setStatusFilter }) {
  return {
    searchInputProps: {
      value: search,
      onChange: (e) => setSearch(e.target.value),
      placeholder: 'Rechercher entreprises... (min 3 chars)',
      'aria-label': 'Rechercher entreprises',
    },
    statusProps: {
      value: statusFilter,
      onChange: (e) => setStatusFilter(e.target.value),
    },
  }
}