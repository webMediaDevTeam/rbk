export function useClientsHistoryToolbar({ search, setSearch }) {
  return {
    searchInputProps: {
      value: search,
      onChange: (e) => setSearch(e.target.value),
      placeholder: 'Rechercher clients... (min 3 chars)',
      'aria-label': 'Rechercher clients',
    },
  }
}