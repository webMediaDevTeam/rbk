export function useComercialToolbar({ search, setSearch, statusFilter, setStatusFilter }) {
  return {
    searchInputProps: {
      value: search,
      onChange: (e) => setSearch(e.target.value),
      placeholder: 'Rechercher employés... (min 3 chars)',
      'aria-label': 'Rechercher employés',
    },
    statusProps: {
      value: statusFilter,
      onChange: (e) => setStatusFilter(e.target.value),
    },
  }
}