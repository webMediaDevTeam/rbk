import { useState } from 'react'
import { useFilterOptions } from '@/hooks/use-filter-options.js'

export function useProspectToolbar({
  search, setSearch,
  municipality, setMunicipality,
  categories, setCategories,
  region, setRegion,
}) {
  const { municipalitiesList, categoriesList, regionsList } = useFilterOptions()

  return {
    search,
    setSearch,
    municipality,
    setMunicipality,
    categories,
    setCategories,
    region,
    setRegion,
    municipalitiesList,
    categoriesList,
    regionsList,
  }
}
