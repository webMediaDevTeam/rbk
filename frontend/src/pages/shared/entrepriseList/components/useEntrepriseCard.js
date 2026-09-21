export function useEntrepriseCard({ entreprise, onAvatarClick, onToggleStatus, onDelete, canDelete, onEdit }) {
  const name = entreprise.name ?? entreprise.profil?.nom ?? entreprise.email ?? '—'
  const email = entreprise.email ?? '—'
  const phone = entreprise.phone ?? entreprise.profil?.telephone ?? '—'
  const taxNumber = entreprise.tax_number ?? entreprise.profil?.numero_fiscal ?? '—'
  const toggleLabel = entreprise.status === 'ACTIVE' ? 'Désactiver' : 'Activer'

  const handleAvatarClick = () => onAvatarClick?.(entreprise)
  const handleEdit = (closeMenu) => {
    onEdit?.(entreprise)
    closeMenu()
  }
  const handleToggleStatus = (closeMenu) => {
    onToggleStatus?.(entreprise.id, entreprise.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE')
    closeMenu()
  }
  const handleDelete = (closeMenu) => {
    onDelete?.(entreprise.id)
    closeMenu()
  }

  return {
    entreprise,
    name,
    email,
    phone,
    taxNumber,
    toggleLabel,
    canDelete,
    handleAvatarClick,
    handleEdit,
    handleToggleStatus,
    handleDelete,
  }
}
