export function useHistoryToolbar({ search, setSearch }) {
  return {
    searchInputProps: {
      value: search,
      onChange: (e) => setSearch(e.target.value),
      placeholder: 'Rechercher un client appelé... (min 3 chars)',
      'aria-label': 'Rechercher clients',
    },
  }
}