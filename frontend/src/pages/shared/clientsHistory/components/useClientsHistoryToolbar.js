export function useClientsHistoryToolbar({ search, setSearch }) {
  return {
    searchInputProps: {
      value: search,
      onChange: (e) => setSearch(e.target.value),
      placeholder: 'Rechercher : nom, entreprise, téléphone, NEQ, licence, catégorie… (3 car. — 2 pour un numéro)',
      'aria-label': 'Rechercher clients',
    },
  }
}