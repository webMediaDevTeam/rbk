import { Link } from 'react-router-dom'
import { ChevronRight, Eye, Home } from 'lucide-react'
import { useEntrepriseDetailPage } from './useEntrepriseDetail.js'
import StatCards from '@/pages/shared/comercialDetail/components/StatCards.jsx'
import Tabs from '@/pages/shared/comercialDetail/components/Tabs.jsx'
import HistoryList from '@/pages/shared/comercialDetail/components/HistoryList.jsx'
import HistoryToolbar from '@/pages/shared/comercialDetail/components/HistoryToolbar.jsx'
import ProspectKpis from '@/pages/shared/components/ProspectKpis/index.jsx'
import EnterpriseInfoCard from './components/EnterpriseInfoCard.jsx'
import EmployeesCard from './components/EmployeesCard.jsx'

/**
 * Fiche d'une entreprise — `/entreprises/:id` (ADMIN / SUPER_ADMIN).
 *
 * Reprend la **même grammaire** que le détail d'un employé
 * (`pages/shared/comercialDetail`) : cartes de statistiques, onglets
 * « Détails / Employés / Historique », barre de badges = filtre de statut.
 * L'historique est celui de **tous les employés** de l'entreprise
 * (`GET entreprises/{id}/stats`).
 */
export default function EntrepriseDetailPage() {
  const {
    TABS,
    isLoading,
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
    entreprise,
    analytics,
    employees,
    historique,
    clients,
    name,
    companyName,
    handleViewDetail,
  } = useEntrepriseDetailPage()

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <nav className="flex items-center gap-1.5 text-sm text-muted-foreground">
        <Link to="/entreprises" className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
          <Home className="h-3.5 w-3.5" /> Accueil
        </Link>
        <ChevronRight className="h-3.5 w-3.5" />
        <Link to="/entreprises" className="hover:text-foreground transition-colors">Entreprises</Link>
        <ChevronRight className="h-3.5 w-3.5" />
        <span className="font-medium text-foreground">{name}</span>
      </nav>

      <div className="flex items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground truncate">{name}</h1>
          <p className="text-sm text-muted-foreground mt-1">
            Statistiques de l'entreprise, de ses employés et de leurs appels.
          </p>
        </div>
        <Link
          to="/entreprises"
          className="inline-flex items-center gap-2 px-2.5 lg:px-4 h-9 rounded-lg text-sm font-medium bg-muted text-foreground hover:bg-muted/80 transition-colors"
        >
          <Eye className="h-4 w-4" /> Retour
        </Link>
      </div>

      {isLoading ? (
        <div className="h-48 flex items-center justify-center text-muted-foreground">Chargement...</div>
      ) : !entreprise ? (
        <div className="h-48 flex items-center justify-center text-muted-foreground">Entreprise introuvable.</div>
      ) : (
        <>
          <StatCards analytics={analytics} />

          <Tabs tabs={TABS} active={activeTab} onChange={setActiveTab} />

          {activeTab === 'details' && <EnterpriseInfoCard entreprise={entreprise} />}

          {activeTab === 'employes' && (
            <EmployeesCard employees={employees} companyName={companyName} />
          )}

          {activeTab === 'historique' && (
            <div className="space-y-6">
              {/* Badges de la colonne « Statut » = filtre (sélection unique),
                  compteurs limités aux appels des employés de l'entreprise. */}
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
        </>
      )}
    </div>
  )
}
