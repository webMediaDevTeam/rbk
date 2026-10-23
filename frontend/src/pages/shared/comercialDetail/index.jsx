import { useState } from 'react'
import { useComercialDetailPage } from './useComercialDetail.js'
import PageHeader from './components/PageHeader.jsx'
import StatCards from './components/StatCards.jsx'
import Tabs from './components/Tabs.jsx'
import CommercialInfoCard from './components/CommercialInfoCard.jsx'
import CallLogsCard from './components/CallLogsCard.jsx'
import HistoryList from './components/HistoryList.jsx'
import HistoryToolbar from './components/HistoryToolbar.jsx'
import ProspectKpis from '@/pages/shared/components/ProspectKpis/index.jsx'
import PeriodFilterBar from '@/pages/shared/components/PeriodFilterBar/index.jsx'
import CommercialUpdateModal from '@/pages/shared/components/CommercialUpdateModal/index.jsx'

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
    period,
    periodYears,
    commercial,
    updateTarget,
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

  // Modale « Modifier l'employé » : la même que celle de la page « Employés »,
  // nourrie par `updateTarget` (projection `user` de la fiche, à la forme des
  // lignes de la liste). L'enregistrement invalide la fiche par préfixe de
  // clé — liste, stats et badges reviennent donc déjà à jour.
  const [showEdit, setShowEdit] = useState(false)

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <PageHeader
        name={name}
        onEdit={updateTarget ? () => setShowEdit(true) : undefined}
      />
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
          {/* Période de l'historique : borne la liste **et** les badges sur la
              date de l'appel de cet employé. Sans fenêtre posée = tout
              l'historique ; « Cette année » et « Réinitialiser » sont deux
              états distincts à l'écran. */}
          <PeriodFilterBar
            years={periodYears}
            year={period.year}
            month={period.month}
            week={period.week}
            day={period.day}
            range={period.range}
            preset={period.preset}
            isDefault={period.isDefault}
            scope="la liste et les badges de cet employé"
            onYearChange={period.setYear}
            onMonthChange={period.setMonth}
            onWeekChange={period.setWeek}
            onDayChange={period.setDay}
            onPresetChange={period.applyPreset}
            onReset={period.resetPeriod}
          />
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

      <CommercialUpdateModal
        open={showEdit}
        user={updateTarget}
        queryKey={['comercialDetail', id]}
        onClose={() => setShowEdit(false)}
      />
    </div>
  )
}