import { useEffect, useState } from 'react'
import { api } from '@/api/client.js'

export function useProspectToolbar({ search, setSearch, categories, setCategories }) {
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

  return {
    search,
    setSearch,
    categories,
    setCategories,
    categoriesList,
  }
}