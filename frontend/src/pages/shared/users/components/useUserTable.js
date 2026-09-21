export function useUserTable({ users, selectedUsers, setSelectedUsers }) {
  const isAllSelected = users.length > 0 && selectedUsers.length === users.length

  const toggleSelectAll = () => {
    if (isAllSelected) {
      setSelectedUsers([])
    } else {
      setSelectedUsers(users.map((u) => u.id))
    }
  }

  const toggleSelectRow = (id) => {
    if (selectedUsers.includes(id)) {
      setSelectedUsers(selectedUsers.filter((item) => item !== id))
    } else {
      setSelectedUsers([...selectedUsers, id])
    }
  }

  const isRowSelected = (id) => selectedUsers.includes(id)

  return { isAllSelected, toggleSelectAll, toggleSelectRow, isRowSelected }
}