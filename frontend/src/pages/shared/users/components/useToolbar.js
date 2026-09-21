import { useState } from 'react'

const STATUS_OPTIONS = ['Active', 'Suspended', 'Invited']
const ROLE_OPTIONS = ['Admin', 'Manager', 'Cashier']

export function useToolbar({
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

  const openDrawer = () => setDrawerOpen(true)
  const closeDrawer = () => setDrawerOpen(false)

  const clearFilters = () => {
    setStatusFilter('')
    setRoleFilter('')
  }

  const handleSearchChange = (e) => setSearch(e.target.value)
  const handleStatusFilterChange = (e) => setStatusFilter(e.target.value)
  const handleRoleFilterChange = (e) => setRoleFilter(e.target.value)

  const toggleStatusFilter = () => setStatusFilter(statusFilter === 'Active' ? '' : 'Active')
  const toggleRoleFilter = () => setRoleFilter(roleFilter === 'Admin' ? '' : 'Admin')

  const setTableMode = () => setViewMode('table')
  const setCardMode = () => setViewMode('card')

  const activeFilterCount = [statusFilter, roleFilter].filter(Boolean).length

  return {
    drawerOpen,
    openDrawer,
    closeDrawer,
    clearFilters,
    handleSearchChange,
    handleStatusFilterChange,
    handleRoleFilterChange,
    toggleStatusFilter,
    toggleRoleFilter,
    setTableMode,
    setCardMode,
    activeFilterCount,
    STATUS_OPTIONS,
    ROLE_OPTIONS,
  }
}