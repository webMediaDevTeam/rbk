import { normalizeRcNumber } from '../useComercialList.js'

export function useComercialCard({ comercial, onAvatarClick, onToggleStatus, onDelete, canDelete, onEdit, duplicateRingCentralNumbers = new Set() }) {
  const profil = comercial.profil
  const name = profil ? `${profil.prenom ?? ''} ${profil.nom ?? ''}`.trim() || comercial.email : (comercial.first_name ? `${comercial.first_name} ${comercial.last_name ?? ''}`.trim() : comercial.email)
  const enterpriseName = profil?.entreprise_name ?? '—'
  const phone = profil?.telephone ?? comercial.phone ?? '—'
  // Numéro RingCentral de l'employé (source d'appel) + alerte de doublon.
  const rcNumber = profil?.ringcentral_from_number ?? null
  const rcKey = normalizeRcNumber(rcNumber)
  const rcDuplicate = rcKey !== '' && duplicateRingCentralNumbers.has(rcKey)
  const toggleLabel = comercial.status === 'ACTIVE' ? 'Désactiver' : 'Activer'
  // Opérateur : pas de fiche employé ni de page de détail commerciale.
  const isOperator = comercial.role === 'OPERATOR'

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
    rcNumber,
    rcDuplicate,
    isOperator,
    toggleLabel,
    canDelete,
    handleAvatarClick,
    handleEdit,
    handleToggleStatus,
    handleDelete,
  }
}
