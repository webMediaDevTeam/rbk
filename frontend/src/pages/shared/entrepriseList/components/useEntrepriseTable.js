export function useEntrepriseTable({ entreprises, sortBy, sortOrder, onSort, onToggleStatus, onDelete, canDelete, onAvatarClick, onEdit }) {
  const rows = entreprises.map((ent) => {
    const name = ent.name ?? ent.profil?.nom ?? ent.email ?? '—'
    const email = ent.email ?? '—'
    const phone = ent.phone ?? ent.profil?.telephone ?? '—'
    const taxNumber = ent.tax_number ?? ent.profil?.numero_fiscal ?? '—'
    const toggleLabel = ent.status === 'ACTIVE' ? 'Désactiver' : 'Activer'

    const handleAvatarClick = () => onAvatarClick?.(ent)
    const handleEdit = (closeMenu) => {
      onEdit?.(ent)
      closeMenu()
    }
    const handleToggleStatus = (closeMenu) => {
      onToggleStatus(ent.id, ent.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE')
      closeMenu()
    }
    const handleDelete = (closeMenu) => {
      onDelete(ent.id)
      closeMenu()
    }

    return {
      key: ent.id,
      ent,
      name,
      email,
      phone,
      taxNumber,
      toggleLabel,
      handleAvatarClick,
      handleEdit,
      handleToggleStatus,
      handleDelete,
    }
  })

  return { rows, sortBy, sortOrder, onSort, canDelete }
}
