import { normalizeRcNumber, gpsBypassHint } from '../useComercialList.js'

export function useComercialCard({ comercial, statsById = {}, onAvatarClick, onToggleStatus, onToggleGpsBypass, onTogglePrivilege, onDelete, canDelete, onEdit, duplicateRingCentralNumbers = new Set() }) {
  const profil = comercial.profil
  const name = profil ? `${profil.prenom ?? ''} ${profil.nom ?? ''}`.trim() || comercial.email : (comercial.first_name ? `${comercial.first_name} ${comercial.last_name ?? ''}`.trim() : comercial.email)
  const enterpriseName = profil?.entreprise_name ?? '—'
  const phone = profil?.telephone ?? comercial.phone ?? '—'
  // Numéro RingCentral de l'employé (source d'appel) + alerte de doublon.
  const rcNumber = profil?.ringcentral_from_number ?? null
  const rcKey = normalizeRcNumber(rcNumber)
  const rcDuplicate = rcKey !== '' && duplicateRingCentralNumbers.has(rcKey)
  const toggleLabel = comercial.status === 'ACTIVE' ? 'Désactiver' : 'Activer'
  // Dérogation GPS (`users.gps_bypass`, bool serveur) : connexion hors zones,
  // **limitée à 24 h** — le libellé indique le temps restant avant retrait.
  const gpsBypass = Boolean(comercial.gps_bypass)
  const bypassHint = gpsBypassHint(gpsBypass, comercial.gps_bypass_expires_at ?? null)
  // Privilège commercial (`users.has_permission`) — switch inline comme la
  // carte « Hors zones » ; réservé au COMERCIAL (§7.1 : ne protège que la
  // libération de la liste).
  const hasPermission = Number(comercial.has_permission) === 1
  // Opérateur : pas de fiche employé ni de page de détail commerciale.
  const isOperator = comercial.role === 'OPERATOR'
  // Compteurs d'activité (puces Clients / BV / Rappel / Oui) — carte
  // `id → stats` chargée en une requête pour toute la page.
  const stats = statsById[comercial.id] ?? null

  const handleAvatarClick = () => onAvatarClick?.(comercial)
  const handleEdit = (closeMenu) => {
    onEdit?.(comercial)
    closeMenu()
  }
  const handleToggleStatus = (closeMenu) => {
    onToggleStatus?.(comercial.id, comercial.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE')
    closeMenu()
  }
  const handleToggleGpsBypass = (value) => onToggleGpsBypass?.(comercial.id, value)
  const handleTogglePrivilege = (value) => onTogglePrivilege?.(comercial.id, value)
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
    gpsBypass,
    gpsBypassHint: bypassHint,
    hasPermission,
    isOperator,
    stats,
    toggleLabel,
    canDelete,
    handleAvatarClick,
    handleEdit,
    handleToggleStatus,
    handleToggleGpsBypass,
    handleTogglePrivilege,
    handleDelete,
  }
}
