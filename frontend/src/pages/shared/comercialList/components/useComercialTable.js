import { normalizeRcNumber } from '../useComercialList.js'

export function useComercialTable({ commerciaux, sortBy, sortOrder, onSort, onToggleStatus, onDelete, canDelete, onAvatarClick, onEdit, duplicateRingCentralNumbers = new Set() }) {
  const rows = commerciaux.map((c) => {
    const profil = c.profil
    const name = profil ? `${profil.prenom ?? ''} ${profil.nom ?? ''}`.trim() || c.email : (c.first_name ? `${c.first_name} ${c.last_name ?? ''}`.trim() : c.email)
    const enterpriseName = profil?.entreprise_name ?? '—'
    const enterpriseSource = profil?.entreprise?.source ?? null
    const phone = profil?.telephone ?? c.phone ?? '—'
    // Numéro RingCentral de l'employé (source d'appel) + alerte de doublon.
    const rcNumber = profil?.ringcentral_from_number ?? null
    const rcKey = normalizeRcNumber(rcNumber)
    const rcDuplicate = rcKey !== '' && duplicateRingCentralNumbers.has(rcKey)
    const toggleLabel = c.status === 'ACTIVE' ? 'Désactiver' : 'Activer'
    // Privilège de libération (`users.has_permission`) : 1 = « Oui », sinon « - ».
    const hasPermission = Number(c.has_permission) === 1

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
      enterpriseSource,
      phone,
      rcNumber,
      rcDuplicate,
      hasPermission,
      toggleLabel,
      handleAvatarClick,
      handleEdit,
      handleToggleStatus,
      handleDelete,
    }
  })

  return { rows, sortBy, sortOrder, onSort, canDelete }
}
