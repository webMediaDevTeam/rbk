import { useClientsHistoryPage } from './useClientsHistory.js'
import { useAuth } from '@/context/AuthContext.jsx'
import ProspectTable from '@/pages/comercial/ProspectList/components/ProspectTable.jsx'
import ProspectCard from '@/pages/comercial/ProspectList/components/ProspectCard.jsx'
import ClientsHistoryToolbar from './components/ClientsHistoryToolbar.jsx'
import SourceTabs from './components/SourceTabs.jsx'
import Pagination from '@/pages/shared/components/Pagination/index.jsx'
import ProspectKpis from '@/pages/shared/components/ProspectKpis/index.jsx'
import ClientEditModal from '@/pages/shared/components/ClientEditModal/index.jsx'

export default function ClientsHistoryPage() {
  // L'`OPERATOR` partage cette page, mais **sans aucun filtre** : l'API lui
  // rend d'office les « Disponibles sans téléphone » (`phone IS NULL`), et
  // ignore tout paramètre. L'UI retire donc badges KPI, facettes et actions
  // de gestion (liste noire, accès au détail) — il ne reste que recherche,
  // lignes et modale « Modifier le client ».
  const { role } = useAuth()
  const isOperator = role === 'OPERATOR'

  const {
    isDesktop,
    isLoading,
    search,
    handleSearchChange,
    statusFilters,
    handleStatusToggle,
    kpFilters,
    municipality,
    handleMunicipalityChange,
    categories,
    handleCategoryChange,
    region,
    handleRegionChange,
    enterprise,
    handleEnterpriseChange,
    employee,
    handleEmployeeChange,
    source,
    handleSourceChange,
    currentPage,
    setCurrentPage,
    rowsPerPage,
    handleRowsPerPageChange,
    totalPages,
    clients,
    sortBy,
    sortOrder,
    handleSort,
    handleViewDetail,
    blacklistId,
    toggleBlacklist,
    phoneClient,
    openPhoneEdit,
    closePhoneEdit,
  } = useClientsHistoryPage()

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <div className="flex items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Grande liste</h1>
          <p className="text-sm text-muted-foreground mt-1">
            {isOperator
              ? 'Prospects disponibles sans téléphone : complétez leurs fiches.'
              : 'Tous les prospects de la base, sans restriction.'}
          </p>
        </div>
      </div>

      {/* Badges de la colonne « Statut » = filtre (sélection **unique**,
          couleur pleine pour l'état sélectionné) : une seule valeur par
          ligne, deux dimensions disjointes (statut client / réservation).
          `filters` (recherche, facettes, onglet « source », employé) borne les
          compteurs au **même** périmètre que les lignes rendues — changer un
          filtre ré-render donc les valeurs des badges. Masqué pour
          l'`OPERATOR` : son périmètre est fixe, les badges n'auraient aucun
          effet. */}
      {!isOperator && (
        <ProspectKpis statusFilters={statusFilters} onStatusFilterChange={handleStatusToggle} filters={kpFilters} />
      )}

      {/* Onglets « Tous / Affaire / … » = filtre `?source=` (origine du
          prospect) — **réservés ADMIN / SUPER_ADMIN** : la barre n'est pas
          rendue sans le privilège `clients:filter-source`, et le commercial
          n'ouvre pas cette page. */}
      {!isOperator && <SourceTabs source={source} onChange={handleSourceChange} />}

      <ClientsHistoryToolbar
        search={search} setSearch={handleSearchChange}
        municipality={municipality} setMunicipality={handleMunicipalityChange}
        categories={categories} setCategories={handleCategoryChange}
        region={region} setRegion={handleRegionChange}
        enterprise={enterprise} setEnterprise={handleEnterpriseChange}
        employee={employee} setEmployee={handleEmployeeChange}
        showFacets={!isOperator}
      />

      {isLoading ? (
        <div className="h-48 flex items-center justify-center text-muted-foreground">Chargement...</div>
      ) : isDesktop ? (
        /* Ligne non cliquable (panel admin/super admin) : on ne navigue plus
           vers la fiche depuis la ligne ; la dernière colonne porte « Voir »
           et la bascule **liste noire / débloquer** (action directe, sans
           modale de confirmation). Pour l'`OPERATOR` : ni « Voir » ni liste
           noire — seule la modale « Modifier le client » reste. */
        <ProspectTable
          clients={clients}
          startIndex={(currentPage - 1) * rowsPerPage}
          sortBy={sortBy} sortOrder={sortOrder} onSort={handleSort}
          onViewDetail={handleViewDetail}
          showViewButton={!isOperator}
          rowClickable={false}
          showPhone
          onToggleBlacklist={isOperator ? undefined : toggleBlacklist}
          onEditPhone={openPhoneEdit}
          blacklistId={blacklistId}
        />
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
          {clients.map((c, i) => (
            <ProspectCard
              key={c.id}
              num={(currentPage - 1) * rowsPerPage + i + 1}
              client={c}
              onViewDetail={handleViewDetail}
              showViewButton={!isOperator}
              rowClickable={false}
              showPhone
              onToggleBlacklist={isOperator ? undefined : toggleBlacklist}
              onEditPhone={openPhoneEdit}
              blacklistId={blacklistId}
            />
          ))}
        </div>
      )}

      <Pagination
        currentPage={currentPage}
        totalPages={totalPages}
        rowsPerPage={rowsPerPage}
        onPageChange={setCurrentPage}
        onRowsPerPageChange={handleRowsPerPageChange}
      />

      <ClientEditModal open={Boolean(phoneClient)} client={phoneClient} onClose={closePhoneEdit} />
    </div>
  )
}