import { useState } from 'react'
import { Mail, MoreVertical, UserPlus } from 'lucide-react'
import Button from '@/components/ui/button'

export default function Header({ onInvite, onAddUser }) {
  const [menuOpen, setMenuOpen] = useState(false)

  return (
    <div className="flex items-center justify-between gap-3">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-foreground">User List</h1>
        <p className="text-sm text-muted-foreground mt-1">Manage your users and their roles here.</p>
      </div>

      {/* Desktop buttons */}
      <div className="hidden sm:flex items-center gap-2">
        <Button variant="secondary" size="md" onClick={onInvite}>
          <Mail className="h-4 w-4" />
          Invite User
        </Button>
        <Button variant="default" size="md" onClick={onAddUser}>
          <UserPlus className="h-4 w-4" />
          Add User
        </Button>
      </div>

      {/* Mobile dropdown */}
      <div className="relative sm:hidden">
        <Button
          variant="secondary"
          size="icon"
          onClick={() => setMenuOpen((v) => !v)}
          aria-label="User actions"
        >
          <MoreVertical className="h-4 w-4" />
        </Button>
        {menuOpen && (
          <>
            <button
              className="fixed inset-0 z-10 cursor-default"
              aria-hidden="true"
              tabIndex={-1}
              onClick={() => setMenuOpen(false)}
            />
            <div className="absolute right-0 top-full mt-1 z-20 w-48 rounded-lg border border-border bg-card text-card-foreground p-1 shadow-lg">
              <button
                onClick={() => { onInvite(); setMenuOpen(false) }}
                className="flex w-full items-center rounded-md px-3 py-2 text-sm hover:bg-muted transition-colors gap-2"
              >
                <Mail className="h-4 w-4 text-muted-foreground" />
                Invite User
              </button>
              <button
                onClick={() => { onAddUser(); setMenuOpen(false) }}
                className="flex w-full items-center rounded-md px-3 py-2 text-sm hover:bg-muted transition-colors gap-2"
              >
                <UserPlus className="h-4 w-4 text-muted-foreground" />
                Add User
              </button>
            </div>
          </>
        )}
      </div>
    </div>
  )
}