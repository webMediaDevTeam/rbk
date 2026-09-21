import { ChevronRight, Home } from 'lucide-react'
import Header from './components/Header'
import Toolbar from './components/Toolbar'
import UserTable from './components/UserTable'
import UserCard from './components/UserCard'
import Pagination from './components/Pagination'
import { useUsers } from './useUsers'

export default function UsersPage() {
  const {
    users,
    selectedUsers,
    setSelectedUsers,
    search,
    setSearch,
    statusFilter,
    setStatusFilter,
    roleFilter,
    setRoleFilter,
    currentPage,
    setCurrentPage,
    rowsPerPage,
    setRowsPerPage,
    viewMode,
    setViewMode,
    toggleSelect,
    handleInvite,
    handleAddUser,
    handleSort,
    handleHomeClick,
  } = useUsers()

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <nav className="flex items-center gap-1.5 text-sm text-muted-foreground">
        <a href="#" onClick={handleHomeClick} className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
          <Home className="h-3.5 w-3.5" />
          Home
        </a>
        <ChevronRight className="h-3.5 w-3.5" />
        <span className="font-medium text-foreground">Users</span>
      </nav>

      <Header
        onInvite={handleInvite}
        onAddUser={handleAddUser}
      />
      <Toolbar
        search={search}
        setSearch={setSearch}
        statusFilter={statusFilter}
        setStatusFilter={setStatusFilter}
        roleFilter={roleFilter}
        setRoleFilter={setRoleFilter}
        viewMode={viewMode}
        setViewMode={setViewMode}
      />

      {viewMode === 'table' ? (
        <UserTable
          users={users}
          selectedUsers={selectedUsers}
          setSelectedUsers={setSelectedUsers}
          onSort={handleSort}
        />
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
          {users.map((user) => (
            <UserCard
              key={user.id}
              user={user}
              isSelected={selectedUsers.includes(user.id)}
              onSelect={toggleSelect}
            />
          ))}
        </div>
      )}

      <Pagination
        currentPage={currentPage}
        totalPages={50}
        rowsPerPage={rowsPerPage}
        onPageChange={setCurrentPage}
        onRowsPerPageChange={setRowsPerPage}
      />
    </div>
  )
}