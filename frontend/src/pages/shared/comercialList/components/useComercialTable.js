export function useComercialTable({ commerciaux, sortBy, sortOrder, onSort, onToggleStatus, onDelete, canDelete, onAvatarClick, onEdit }) {
  const rows = commerciaux.map((c) => {
    const profil = c.profil
    const name = profil ? `${profil.prenom ?? ''} ${profil.nom ?? ''}`.trim() || c.email : (c.first_name ? `${c.first_name} ${c.last_name ?? ''}`.trim() : c.email)
    const enterpriseName = profil?.entreprise_name ?? '—'
    const phone = profil?.telephone ?? c.phone ?? '—'
    const toggleLabel = c.status === 'ACTIVE' ? 'Désactiver' : 'Activer'

    const handleAvatarClick = () => onAvatarClick?.(c)
    const handleEdit = (closeMenu) => {
      onEdit?.(c)
      closeMenu()
    }
    const handleToggleStatus = (closeMenu) => {
      onToggleStatus(c.id, c.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE')
      closeMenu()
    }
    const handleDelete = (closeMenu) => {
      onDelete(c.id)
      closeMenu()
    }

    return {
      key: c.id,
      c,
      name,
      enterpriseName,
      phone,
      toggleLabel,
      handleAvatarClick,
      handleEdit,
      handleToggleStatus,
      handleDelete,
    }
  })

  return { rows, sortBy, sortOrder, onSort, canDelete }
}
