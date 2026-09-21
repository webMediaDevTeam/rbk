import { useState } from 'react'

export function useHeader({ onInvite, onAddUser }) {
  const [menuOpen, setMenuOpen] = useState(false)

  const toggleMenu = () => setMenuOpen((v) => !v)
  const closeMenu = () => setMenuOpen(false)

  const handleInvite = () => {
    onInvite()
    setMenuOpen(false)
  }

  const handleAddUser = () => {
    onAddUser()
    setMenuOpen(false)
  }

  return { menuOpen, toggleMenu, closeMenu, handleInvite, handleAddUser }
}