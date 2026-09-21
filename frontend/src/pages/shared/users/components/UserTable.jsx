import { ArrowUpDown, MoreHorizontal } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import StatusBadge from './StatusBadge'
import RoleBadge from './RoleBadge'
import { useUserTable } from './useUserTable.js'

export default function UserTable({ users, selectedUsers, setSelectedUsers, onSort }) {
  const { isAllSelected, toggleSelectAll, toggleSelectRow, isRowSelected } = useUserTable({ users, selectedUsers, setSelectedUsers })

  return (
    <div className="rounded-xl bg-card text-card-foreground shadow-sm overflow-hidden">
      <Table>
        <TableHeader>
          <TableRow className="bg-background hover:bg-background">
            <TableHead className="w-10">
              <input
                type="checkbox"
                checked={isAllSelected}
                onChange={toggleSelectAll}
                className="h-4 w-4 rounded border-border accent-primary"
              />
            </TableHead>
            <TableHead>
              <button onClick={() => onSort('username')} className="flex items-center gap-1 cursor-pointer font-semibold">
                Username
                <ArrowUpDown className="h-3.5 w-3.5" />
              </button>
            </TableHead>
            <TableHead>Name</TableHead>
            <TableHead>
              <button onClick={() => onSort('email')} className="flex items-center gap-1 cursor-pointer font-semibold">
                Email
                <ArrowUpDown className="h-3.5 w-3.5" />
              </button>
            </TableHead>
            <TableHead>Phone Number</TableHead>
            <TableHead>Status</TableHead>
            <TableHead>Role</TableHead>
            <TableHead className="w-10" />
          </TableRow>
        </TableHeader>
        <TableBody>
          {users.map((user) => (
            <TableRow key={user.id} data-state={isRowSelected(user.id) ? 'selected' : undefined}>
              <TableCell>
                <input
                  type="checkbox"
                  checked={isRowSelected(user.id)}
                  onChange={() => toggleSelectRow(user.id)}
                  className="h-4 w-4 rounded border-border accent-primary"
                />
              </TableCell>
              <TableCell className="font-medium">{user.username}</TableCell>
              <TableCell>{user.name}</TableCell>
              <TableCell className="text-muted-foreground">{user.email}</TableCell>
              <TableCell className="text-muted-foreground">{user.phone}</TableCell>
              <TableCell><StatusBadge status={user.status} /></TableCell>
              <TableCell><RoleBadge role={user.role} /></TableCell>
              <TableCell className="text-right">
                <button className="inline-flex items-center justify-center h-8 w-8 rounded-md hover:bg-muted text-muted-foreground transition-colors">
                  <MoreHorizontal className="h-4 w-4" />
                </button>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
      {users.length === 0 && (
        <div className="h-24 flex items-center justify-center text-muted-foreground">No results.</div>
      )}
    </div>
  )
}