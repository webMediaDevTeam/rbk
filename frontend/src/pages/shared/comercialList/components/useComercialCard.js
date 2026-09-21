export function useComercialCard({ comercial, onAvatarClick, onToggleStatus, onDelete, canDelete, onEdit }) {
  const profil = comercial.profil
  const name = profil ? `${profil.prenom ?? ''} ${profil.nom ?? ''}`.trim() || comercial.email : (comercial.first_name ? `${comercial.first_name} ${comercial.last_name ?? ''}`.trim() : comercial.email)
  const enterpriseName = profil?.entreprise_name ?? '—'
  const phone = profil?.telephone ?? comercial.phone ?? '—'
  const toggleLabel = comercial.status === 'ACTIVE' ? 'Désactiver' : 'Activer'

  const handleAvatarClick = () => onAvatarClick?.(comercial)
  const handleEdit = (closeMenu) => {
    onEdit?.(comercial)
    closeMenu()
  }
  const handleToggleStatus = (closeMenu) => {
    onToggleStatus?.(comercial.id, comercial.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE')
    closeMenu()
  }
  const handleDelete = (closeMenu) => {
    onDelete?.(comercial.id)
    closeMenu()
  }

  return {
    comercial,
    name,
    enterpriseName,
    phone,
    toggleLabel,
    canDelete,
    handleAvatarClick,
    handleEdit,
    handleToggleStatus,
    handleDelete,
  }
}
