import { Building2, Map, MapPin, Tags, UserCog } from 'lucide-react'
import SearchBar from '@/components/ui/search-bar.jsx'
import Select from '@/components/ui/select'
import { useClientsHistoryToolbar } from './useClientsHistoryToolbar.js'
import { useFilterOptions } from '@/hooks/use-filter-options.js'
import { useInteractionFilters } from '@/hooks/use-interaction-filters.js'

/**
 * Champ de filtre : icône **à gauche** + `<Select>` natif pad-gué pour laisser
 * la place à l'icône. L'icône identifie visuellement chaque filtre (le
 * gestionnaire distingue d'un coup d'œil Entreprise / Employé / Municipalité /
 * Région / Catégorie).
 */
function FilterSelect({ icon: Icon, value, onChange, children }) {
  return (
    <div className="relative">
      <Icon
        className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
        aria-hidden="true"
      />
      <Select
        value={value ?? ''}
        onChange={onChange}
        className="pl-8"
      >
        {children}
      </Select>
    </div>
  )
}

export default function ClientsHistoryToolbar(props) {
  // Le filtre « Statut » (menu déroulant) a été supprimé : ce sont les
  // badges de statut de l'overview, au-dessus, qui filtrent — en sélection
  // unique. La barre pilote la recherche + les filtres géographiques /
  // catégorie + les filtres d'interaction « Entreprise » / « Employé ».
  const { searchInputProps } = useClientsHistoryToolbar(props)
  const { municipalitiesList, categoriesList, regionsList } = useFilterOptions()
  const { enterprises, employees } = useInteractionFilters()
  const {
    municipality, setMunicipality,
    categories, setCategories,
    region, setRegion,
    enterprise, setEnterprise,
    employee, setEmployee,
  } = props

  // Options d'employé : restreintes à l'entreprise choisie le cas échéant,
  // sinon « tous les employés ayant interagi au moins une fois » (renvoyés
  // par le serveur, qui ne liste que ceux-là).
  const employeeOptions = enterprise
    ? employees.filter((e) => e.enterprise_id === enterprise)
    : employees

  return (
    <div className="space-y-3">
      <SearchBar className="w-full" inputProps={searchInputProps} />

      {/* Grille responsive : chaque filtre (icône + libellé explicite dans
          l'option « Toutes / Tous ») garde la même largeur ; le nombre de
          colonnes s'adapte au breakpoint. */}
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
        <FilterSelect icon={Building2} value={enterprise} onChange={(e) => setEnterprise?.(e.target.value)}>
          <option value="">Entreprise: Toutes</option>
          {enterprises.map((en) => (
            <option key={en.id} value={en.id}>{en.name}</option>
          ))}
        </FilterSelect>

        <FilterSelect icon={UserCog} value={employee} onChange={(e) => setEmployee?.(e.target.value)}>
          <option value="">Employé: Tous</option>
          {employeeOptions.map((emp) => (
            <option key={emp.id} value={emp.id}>{emp.name}</option>
          ))}
        </FilterSelect>

        <FilterSelect icon={MapPin} value={municipality} onChange={(e) => setMunicipality?.(e.target.value)}>
          <option value="">Municipalité: Toutes</option>
          {municipalitiesList.map((m) => (
            <option key={m} value={m}>{m}</option>
          ))}
        </FilterSelect>

        <FilterSelect icon={Map} value={region} onChange={(e) => setRegion?.(e.target.value)}>
          <option value="">Région: Toutes</option>
          {regionsList.map((r) => (
            <option key={r} value={r}>{r}</option>
          ))}
        </FilterSelect>

        <FilterSelect icon={Tags} value={categories} onChange={(e) => setCategories?.(e.target.value)}>
          <option value="">Catégorie: Toutes</option>
          {categoriesList.map((c) => (
            <option key={c} value={c}>{c}</option>
          ))}
        </FilterSelect>
      </div>
    </div>
  )
}
