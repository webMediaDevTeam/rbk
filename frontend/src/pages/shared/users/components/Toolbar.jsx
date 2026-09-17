import { useState } from 'react'
import { Filter, LayoutGrid, List, X } from 'lucide-react'
import Button from '../../../../components/ui/button'
import Input from '../../../../components/ui/input'
import Select from '../../../../components/ui/select'

const STATUS_OPTIONS = ['Active', 'Suspended', 'Invited']
const ROLE_OPTIONS = ['Admin', 'Manager', 'Cashier']

export default function Toolbar({
  search,
  setSearch,
  statusFilter,
  setStatusFilter,
  roleFilter,
  setRoleFilter,
  viewMode,
  setViewMode,
}) {
  const [drawerOpen, setDrawerOpen] = useState(false)

  const clearFilters = () => {
    setStatusFilter('')
    setRoleFilter('')
  }

  const activeFilterCount = [statusFilter, roleFilter].filter(Boolean).length

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex w-full md:w-auto flex-wrap items-center gap-3">
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Filter users..."
            className="md:w-64 w-full min-w-0 flex-1 md:flex-none md:min-w-64"
          />
          <Button
            variant="secondary"
            size="md"
            className="md:hidden"
            onClick={() => setDrawerOpen(true)}
          >
            <Filter className="h-4 w-4" />
            Filter
            {activeFilterCount > 0 && (
              <span className="inline-flex items-center justify-center h-4 min-w-4 px-1 rounded-full bg-primary text-primary-foreground text-xs font-semibold">
                {activeFilterCount}
              </span>
            )}
          </Button>
        </div>

        {/* Desktop controls */}
        <div className="hidden md:flex flex-wrap items-center gap-3">
          <Button
            variant="secondary"
            size="md"
            onClick={() => setStatusFilter(statusFilter === 'Active' ? '' : 'Active')}
          >
            Status
            {statusFilter && (
              <span className="inline-flex items-center justify-center h-4 min-w-4 px-1 rounded-full bg-primary text-primary-foreground text-xs font-semibold">
                {statusFilter}
              </span>
            )}
          </Button>
          <Button
            variant="secondary"
            size="md"
            onClick={() => setRoleFilter(roleFilter === 'Admin' ? '' : 'Admin')}
          >
            Role
            {roleFilter && (
              <span className="inline-flex items-center justify-center h-4 min-w-4 px-1 rounded-full bg-primary text-primary-foreground text-xs font-semibold">
                {roleFilter}
              </span>
            )}
          </Button>
          <div className="flex items-center border border-border rounded-lg overflow-hidden shadow-sm">
            <button
              onClick={() => setViewMode('table')}
              className={`inline-flex items-center justify-center px-3 py-1.5 text-sm font-medium transition-colors ${
                viewMode === 'table'
                  ? 'bg-primary text-primary-foreground'
                  : 'bg-card text-foreground hover:bg-muted'
              }`}
            >
              <List className="h-4 w-4" />
            </button>
            <button
              onClick={() => setViewMode('card')}
              className={`inline-flex items-center justify-center px-3 py-1.5 text-sm font-medium transition-colors ${
                viewMode === 'card'
                  ? 'bg-primary text-primary-foreground'
                  : 'bg-card text-foreground hover:bg-muted'
              }`}
            >
              <LayoutGrid className="h-4 w-4" />
            </button>
          </div>
        </div>
      </div>

      {/* Mobile filter drawer */}
      {drawerOpen && (
        <>
          <button className="fixed inset-0 h-[100dvh] z-[80] bg-black/40 md:hidden" aria-hidden="true" tabIndex={-1} onClick={() => setDrawerOpen(false)} />
          <div className="fixed inset-y-0 right-0 h-[100dvh] z-[90] w-80 max-w-[85vw] bg-card border-l border-border p-4 shadow-xl md:hidden flex flex-col">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-base font-semibold text-foreground flex items-center gap-2">
                <Filter className="h-4 w-4 text-muted-foreground" />
                Filters
              </h3>
              <Button variant="ghost" size="icon-sm" onClick={() => setDrawerOpen(false)} aria-label="Close filters">
                <X className="h-4 w-4" />
              </Button>
            </div>

            <div className="space-y-4 flex-1">
              <div>
                <label className="text-sm font-medium text-foreground mb-1.5 block">Status</label>
                <Select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
                  <option value="">All statuses</option>
                  {STATUS_OPTIONS.map((s) => <option key={s} value={s}>{s}</option>)}
                </Select>
              </div>
              <div>
                <label className="text-sm font-medium text-foreground mb-1.5 block">Role</label>
                <Select value={roleFilter} onChange={(e) => setRoleFilter(e.target.value)}>
                  <option value="">All roles</option>
                  {ROLE_OPTIONS.map((r) => <option key={r} value={r}>{r}</option>)}
                </Select>
              </div>
            </div>

            <div className="flex items-center gap-2 pt-4 border-t border-border">
              <Button variant="secondary" className="flex-1" onClick={clearFilters}>
                Clear filters
              </Button>
              <Button variant="default" className="flex-1" onClick={() => setDrawerOpen(false)}>
                Apply
              </Button>
            </div>
          </div>
        </>
      )}
    </>
  )
}