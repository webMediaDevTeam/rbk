export function useClientsHistoryToolbar({ search, setSearch, status, setStatus }) {
  return {
    searchInputProps: {
      value: search,
      onChange: (e) => setSearch(e.target.value),
      placeholder: 'Rechercher clients... (min 3 chars)',
      'aria-label': 'Rechercher clients',
    },
    statusProps: {
      value: status,
      onChange: (e) => setStatus(e.target.value),
    },
  }
}