import { useMemo, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useAuth } from '@/context/AuthContext.jsx'
import { useDebouncedValue } from '@/hooks/use-debounced-value.js'
import { useIsDesktop } from '@/hooks/use-mobile.js'
import {
  listCommerciauxApi,
  createCommercialApi,
  updateCommercialApi,
  deleteCommercialApi,
  toggleCommercialStatusApi,
  statistiquesCommerciauxApi,
  commercialsStatsApi,
} from '@/api/entreprise.api.js'
import { syncRingCentralEmployeesApi } from '@/api/shared.api.js'
import { toggleGpsBypassApi } from '@/api/gps.api.js'

/**
 * Phase « Lier les employés à leurs numéros RingCentral » :
 * `POST /call-logs/sync/employees` rapproche chaque fiche employé de son
 * poste (appareil choisi → e-mail → numéro) et stocke la correspondance
 * dans `employees.ringcentral_*` (extension, tous les numéros, appareil +
 * numéro source préremplis si la fiche était vide).
 *
 * @returns {{ syncRingCentral: () => void, isSyncingRingCentral: boolean }}
 */
export function useSyncRingCentral() {
  return useMutation({
    mutationFn: syncRingCentralEmployeesApi,
    onSuccess: (body) => {
      const report = body?.data ?? {}

      toast.success(
        `RingCentral : ${report.matched ?? 0}/${report.total ?? 0} employé(s) relié(s) à un poste` +
          ` (${report.updated ?? 0} fiche(s) mise(s) à jour).`
      )

      if (Array.isArray(report.unmatched) && report.unmatched.length > 0) {
        toast.warning(
          `${report.unmatched.length} employé(s) sans correspondance RingCentral (e-mail ou numéro inconnu).`
        )
      }
    },
    onError: (error) =>
      toast.error(error?.response?.data?.error ?? 'Synchronisation RingCentral impossible.'),
  })
}

export function useComercialList(params = {}) {
  return useQuery({
    queryKey: ['commerciaux', params],
    queryFn: () => listCommerciauxApi(params),
  })
}

export function useComercialStats() {
  return useQuery({
    queryKey: ['commerciaux-stats'],
    queryFn: statistiquesCommerciauxApi,
  })
}

/**
 * Compteurs par employé (puces Clients / BV / Rappel / Oui) — une seule
 * requête groupée côté serveur pour toute la page, indépendamment de la
 * pagination (`data.stats` = carte `id → stats`).
 */
export function useCommercialsStats() {
  return useQuery({
    queryKey: ['commercials-stats'],
    queryFn: commercialsStatsApi,
    staleTime: 30_000,
  })
}

export function useCreateCommercial() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: createCommercialApi,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['commerciaux'] })
      qc.invalidateQueries({ queryKey: ['commerciaux-stats'] })
    },
  })
}

export function useUpdateCommercial() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, ...payload }) => updateCommercialApi(id, payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['commerciaux'] }),
  })
}

export function useDeleteCommercial() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: deleteCommercialApi,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['commerciaux'] })
      qc.invalidateQueries({ queryKey: ['commerciaux-stats'] })
    },
  })
}

export function useToggleCommercialStatus() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, status }) => toggleCommercialStatusApi(id, status),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['commerciaux'] }),
  })
}

/**
 * Dérogation « Connexion hors zones » (`users.gps_bypass`) — seul un rôle
 * contraint (COMERCIAL / OPERATOR) peut la recevoir ; le serveur refuse les
 * autres rôles (`UserController::updateGpsBypass`).
 */
export function useToggleGpsBypass() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, gpsBypass }) => toggleGpsBypassApi(id, gpsBypass),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['commerciaux'] }),
  })
}

/**
 * Clé de comparaison d'un numéro RingCentral : chiffres seuls
 * (`+1 (416) 572-4961` = `14165724961`), pour ignorer le format saisi.
 */
export const normalizeRcNumber = (value) => String(value ?? '').replace(/\D+/g, '')

/**
 * Numéros RingCentral (`profil.ringcentral_from_number`) présents chez **au
 * moins deux** employés de la liste : une `Set` de clés normalisées. L'ADMIN
 * est alerté sur chaque ligne concernée pour corriger la source d'appel.
 */
export function computeDuplicateRingCentralNumbers(commerciaux = []) {
  const counts = new Map()

  for (const c of commerciaux) {
    const key = normalizeRcNumber(c?.profil?.ringcentral_from_number)
    if (key === '') continue
    counts.set(key, (counts.get(key) ?? 0) + 1)
  }

  return new Set([...counts].filter(([, n]) => n > 1).map(([key]) => key))
}

export function useComercialListPage() {
  const { canAccess } = useAuth()
  const isDesktop = useIsDesktop()
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [currentPage, setCurrentPage] = useState(1)
  const [rowsPerPage, setRowsPerPage] = useState(50)
  const [sortBy, setSortBy] = useState('created_at')
  const [sortOrder, setSortOrder] = useState('desc')
  const [avatarTarget, setAvatarTarget] = useState(null)
  const [showCreate, setShowCreate] = useState(false)
  const [editTarget, setEditTarget] = useState(null)

  const debouncedSearch = useDebouncedValue(search, 400)
  const searchParam = debouncedSearch.trim().length >= 3 ? debouncedSearch.trim() : undefined

  const { data } = useComercialList({
    search: searchParam,
    status: statusFilter || undefined,
    page: currentPage,
    per_page: rowsPerPage,
    sort_by: sortBy,
    sort_order: sortOrder,
  })

  const deleteMut = useDeleteCommercial()
  const toggleMut = useToggleCommercialStatus()
  const gpsBypassMut = useToggleGpsBypass()
  const ringCentralSync = useSyncRingCentral()
  const { data: statsData } = useCommercialsStats()

  const commerciaux = data?.data?.utilisateurs ?? []
  const statsById = statsData?.data?.stats ?? {}
  // Numéros RingCentral partagés par plusieurs employés (alerte ADMIN) —
  // périmètre : la page affichée.
  const duplicateRingCentralNumbers = useMemo(
    () => computeDuplicateRingCentralNumbers(commerciaux),
    [commerciaux],
  )
  const total = data?.data?.pagination?.total ?? 0
  const totalPages = Math.max(1, Math.ceil(total / rowsPerPage))
  const canCreate = canAccess('commercials:create')
  const canUpdate = canAccess('commercials:update')

  const handleSort = (column) => {
    if (sortBy === column) {
      setSortOrder((prev) => (prev === 'asc' ? 'desc' : 'asc'))
    } else {
      setSortBy(column)
      setSortOrder('asc')
    }
  }

  const handleToggleStatus = (id, status) =>
    toggleMut.mutate({ id, status }, { onSuccess: () => toast.success('Statut mis à jour.') })

  const handleToggleGpsBypass = (id, gpsBypass) =>
    gpsBypassMut.mutate(
      { id, gpsBypass },
      {
        onSuccess: () =>
          toast.success(gpsBypass ? 'Connexion hors zones autorisée.' : 'Connexion hors zones refusée.'),
        onError: (error) =>
          toast.error(error?.response?.data?.message ?? 'Modification impossible.'),
      },
    )

  const handleDelete = (id) => deleteMut.mutate(id, { onSuccess: () => toast.success('Supprimé.') })

  const handleStatusFilterChange = (s) => {
    setStatusFilter(s)
    setCurrentPage(1)
  }

  const handleRowsPerPageChange = (n) => {
    setRowsPerPage(n)
    setCurrentPage(1)
  }

  const handleOpenCreate = () => setShowCreate(true)
  const handleCloseCreate = () => setShowCreate(false)
  const handleCloseAvatar = () => setAvatarTarget(null)
  const handleCloseEdit = () => setEditTarget(null)
  return {
    isDesktop,
    search,
    setSearch,
    statusFilter,
    handleStatusFilterChange,
    currentPage,
    setCurrentPage,
    rowsPerPage,
    handleRowsPerPageChange,
    totalPages,
    canCreate,
    canUpdate,
    syncRingCentral: ringCentralSync.mutate,
    isSyncingRingCentral: ringCentralSync.isPending,
    commerciaux,
    statsById,
    duplicateRingCentralNumbers,
    sortBy,
    sortOrder,
    handleSort,
    handleToggleStatus,
    handleToggleGpsBypass,
    handleDelete,
    avatarTarget,
    setAvatarTarget,
    handleCloseAvatar,
    showCreate,
    handleOpenCreate,
    handleCloseCreate,
    editTarget,
    setEditTarget,
    handleCloseEdit,
  }
}