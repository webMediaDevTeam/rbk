import { useComercialDetailPage } from './useComercialDetail.js'
import PageHeader from './components/PageHeader.jsx'
import StatCards from './components/StatCards.jsx'
import Tabs from './components/Tabs.jsx'
import CommercialInfoCard from './components/CommercialInfoCard.jsx'
import CallLogsCard from './components/CallLogsCard.jsx'
import HistoryList from './components/HistoryList.jsx'
import HistoryToolbar from './components/HistoryToolbar.jsx'
import ProspectKpis from '@/pages/shared/components/ProspectKpis/index.jsx'

export default function ComercialDetailPage() {
  const {
    TABS,
    id,
    activeTab,
    setActiveTab,
    page,
    setPage,
    rowsPerPage,
    handleRowsPerPageChange,
    search,
    handleSearchChange,
    statusFilters,
    handleStatusToggle,
    badges,
    commercial,
    employee,
    entreprise,
    analytics,
    historique,
    clients,
    name,
    email,
    phone,
    avatarUrl,
    companyName,
    handleViewDetail,
  } = useComercialDetailPage()

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <PageHeader name={name} />
      <StatCards analytics={analytics} />

      <Tabs tabs={TABS} active={activeTab} onChange={setActiveTab} />

      {activeTab === 'details' ? (
        <CommercialInfoCard
          commercial={commercial}
          employee={employee}
          entreprise={entreprise}
          name={name}
          email={email}
          phone={phone}
          avatarUrl={avatarUrl}
          companyName={companyName}
        />
      ) : activeTab === 'appels' ? (
        // Journal RingCentral de l'employé (ADMIN / SUPER_ADMIN) —
        // extension résolue via son appareil, enregistrements jouables.
        <CallLogsCard id={id} />
      ) : (
        <div className="space-y-6">
          {/* Badges de la colonne « Statut » = filtre (sélection unique),
              compteurs limités à l'historique de cet employé. */}
          <ProspectKpis
            statusFilters={statusFilters}
            onStatusFilterChange={handleStatusToggle}
            counts={badges}
          />
          <HistoryToolbar search={search} setSearch={handleSearchChange} />
          <HistoryList
            clients={clients}
            pagination={historique?.pagination}
            currentPage={page}
            rowsPerPage={rowsPerPage}
            onPageChange={setPage}
            onRowsPerPageChange={handleRowsPerPageChange}
            onViewDetail={handleViewDetail}
          />
        </div>
      )}
    </div>
  )
}