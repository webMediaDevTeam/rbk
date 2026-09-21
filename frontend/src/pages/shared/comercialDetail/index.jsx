import { useComercialDetailPage } from './useComercialDetail.js'
import BreadcrumbNav from './components/BreadcrumbNav.jsx'
import PageHeader from './components/PageHeader.jsx'
import StatCards from './components/StatCards.jsx'
import Tabs from './components/Tabs.jsx'
import CommercialInfoCard from './components/CommercialInfoCard.jsx'
import HistoryList from './components/HistoryList.jsx'
import HistoryToolbar from './components/HistoryToolbar.jsx'

export default function ComercialDetailPage() {
  const {
    TABS,
    activeTab,
    setActiveTab,
    page,
    setPage,
    rowsPerPage,
    handleRowsPerPageChange,
    search,
    handleSearchChange,
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
      <BreadcrumbNav name={name} />
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
      ) : (
        <div className="space-y-6">
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