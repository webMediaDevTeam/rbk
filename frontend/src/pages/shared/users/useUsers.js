import { useState, useEffect } from 'react'

const INITIAL_USERS = [
  { id: 1, username: 'clair.spinka45', name: 'Clair Spinka', email: 'clair_marks79@hotmail.com', phone: '+17593407614', status: 'Suspended', role: 'Manager' },
  { id: 2, username: 'timothy_stark', name: 'Timothy Stark', email: 'timothy_kris31@yahoo.com', phone: '+14707314034', status: 'Suspended', role: 'Manager' },
  { id: 3, username: 'easton_schuster57', name: 'Easton Schuster', email: 'easton23@gmail.com', phone: '+16095813030', status: 'Active', role: 'Cashier' },
  { id: 4, username: 'janessa.mosciski', name: 'Janessa Mosciski', email: 'janessa_zulauf74@hotmail.com', phone: '+12026770350', status: 'Invited', role: 'Admin' },
  { id: 5, username: 'teagan.bayer82', name: 'Teagan Bayer', email: 'teagan.koss35@yahoo.com', phone: '+12136895161', status: 'Suspended', role: 'Admin' },
  { id: 6, username: 'mamie.lubowitz69', name: 'Mamie Lubowitz', email: 'mamie.kemmer-white9@yahoo.com', phone: '+15139631349', status: 'Active', role: 'Admin' },
  { id: 7, username: 'kayli.kirlin87', name: 'Kayli Kirlin', email: 'kayli62@yahoo.com', phone: '+18107049315', status: 'Active', role: 'Cashier' },
  { id: 8, username: 'sean.ebert40', name: 'Sean Ebert', email: 'sean.mckenzie68@yahoo.com', phone: '+18536627469', status: 'Suspended', role: 'Manager' },
  { id: 9, username: 'kennedi.prosacco15', name: 'Kennedi Prosacco', email: 'kennedi_sawayn6@yahoo.com', phone: '+13288238617', status: 'Invited', role: 'Cashier' },
  { id: 10, username: 'jaeden_parker56', name: 'Jaeden Parker', email: 'jaeden_kuhic@hotmail.com', phone: '+15137159312', status: 'Invited', role: 'Manager' },
]

export function useUsers() {
  const [users] = useState(INITIAL_USERS)
  const [selectedUsers, setSelectedUsers] = useState([])
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [roleFilter, setRoleFilter] = useState('')
  const [currentPage, setCurrentPage] = useState(1)
  const [rowsPerPage, setRowsPerPage] = useState(10)
  const [viewMode, setViewMode] = useState('table')

  useEffect(() => {
    const mq = window.matchMedia('(max-width: 767px)')
    const handler = (e) => e.matches && setViewMode('card')
    handler(mq)
    mq.addEventListener('change', handler)
    return () => mq.removeEventListener('change', handler)
  }, [])

  const filteredUsers = users.filter(
    (user) =>
      (statusFilter === '' || user.status === statusFilter) &&
      (roleFilter === '' || user.role === roleFilter) &&
      (user.username.toLowerCase().includes(search.toLowerCase()) ||
        user.name.toLowerCase().includes(search.toLowerCase()) ||
        user.email.toLowerCase().includes(search.toLowerCase()))
  )

  const toggleSelect = (id) => {
    setSelectedUsers((prev) =>
      prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]
    )
  }

  return {
    users: filteredUsers,
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
  }
}