import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  createGpsZoneApi,
  deleteGpsZoneApi,
  getGpsSettingsApi,
  listGpsZonesApi,
  updateGpsSettingsApi,
  updateGpsZoneApi,
} from '@/api/gps.api.js'
import { getPosition } from '@/lib/geolocation'

/** Rayon par défaut d'une nouvelle zone (mètres). */
export const DEFAULT_RADIUS_M = 500
export const MIN_RADIUS_M = 50
export const MAX_RADIUS_M = 1000

/** Brouillon vide (aucune zone en édition). */
const EMPTY_EDITING = {
  id: null,
  name: '',
  lat: null,
  lng: null,
  radius_m: DEFAULT_RADIUS_M,
  is_active: true,
}

/**
 * Logique de la page « GPS » (ADMIN / SUPER_ADMIN) : liste des zones,
 * édition d'un cercle (brouillon = création, id renseigné = mise à jour),
 * bascule active et suppression. La cible de recentrage du plan
 * (`flyTarget`) est un { lat, lng, zoom, nonce } — le `nonce` force un
 * `flyTo` même sur la même zone.
 *
 * S'y ajoutent les **réglages globaux** : le coupe-circuit du contrôle à la
 * connexion (`geofence_enabled`, lu et écrit en base par `PUT gps/settings`)
 * et le nombre de zones actives, affiché à côté pour avertir qu'activer sans
 * aucune zone valide bloquerait tous les comptes contraints.
 */
export function useGpsPage() {
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState(EMPTY_EDITING)
  const [flyTarget, setFlyTarget] = useState(null)

  const zonesQuery = useQuery({
    queryKey: ['gps-zones'],
    queryFn: listGpsZonesApi,
  })

  const zones = zonesQuery.data?.zones ?? []

  const settingsQuery = useQuery({
    queryKey: ['gps-settings'],
    queryFn: getGpsSettingsApi,
  })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['gps-zones'] })

  // Bascule du contrôle : le serveur renvoie l'état **relu en base**, c'est lui
  // qui alimente l'affichage (pas d'optimiste — un désaccord visuel sur un
  // coupe-circuit de sécurité serait pire qu'un court délai).
  const settingsMut = useMutation({
    mutationFn: (enabled) => updateGpsSettingsApi(enabled),
    onSuccess: (data) => {
      queryClient.setQueryData(['gps-settings'], (prev) => ({
        ...(prev ?? {}),
        geofence_enabled: Boolean(data?.geofence_enabled),
      }))
      toast.success(
        data?.geofence_enabled
          ? 'Contrôle GPS à la connexion activé.'
          : 'Contrôle GPS à la connexion désactivé.',
      )
    },
    onError: (error) =>
      toast.error(error?.response?.data?.message ?? 'Bascule impossible.'),
  })

  const saveMut = useMutation({
    mutationFn: ({ id, payload }) => (id ? updateGpsZoneApi(id, payload) : createGpsZoneApi(payload)),
    onSuccess: () => {
      toast.success('Zone enregistrée.')
      setEditing(EMPTY_EDITING)
      refresh()
    },
    onError: (error) =>
      toast.error(error?.response?.data?.message ?? 'Enregistrement impossible.'),
  })

  const deleteMut = useMutation({
    mutationFn: (id) => deleteGpsZoneApi(id),
    onSuccess: () => {
      toast.success('Zone supprimée.')
      setEditing((prev) => (prev.id && deleteMut.variables === prev.id ? EMPTY_EDITING : prev))
      refresh()
    },
    onError: () => toast.error('Suppression impossible.'),
  })

  const toggleMut = useMutation({
    mutationFn: ({ id, is_active }) => updateGpsZoneApi(id, { is_active }),
    onSuccess: refresh,
    onError: () => toast.error('Bascule impossible.'),
  })

  /** Clic sur la carte : place (ou déplace) le cercle en édition. */
  const handleMapClick = ({ lat, lng }) => {
    setEditing((prev) => ({ ...prev, lat, lng }))
  }

  /** Sélection depuis la liste latérale : charge la zone + zoome dessus. */
  const selectZone = (zone) => {
    setEditing({
      id: zone.id,
      name: zone.name,
      lat: Number(zone.latitude),
      lng: Number(zone.longitude),
      radius_m: zone.radius_m,
      is_active: Boolean(zone.is_active),
    })
    setFlyTarget({
      lat: Number(zone.latitude),
      lng: Number(zone.longitude),
      zoom: Math.min(15, Math.max(11, 15 - Math.log10(Math.max(zone.radius_m, 1)))),
      nonce: Date.now(),
    })
  }

  const handleDelete = (zone) => {
    if (window.confirm(`Supprimer la zone « ${zone.name} » ?`)) {
      deleteMut.mutate(zone.id)
    }
  }

  /** « Utiliser ma position » : centre la carte et place le cercle. */
  const useMyLocation = async () => {
    const coords = await getPosition()
    if (!coords) {
      toast.error('Géolocalisation indisponible (refusée ou contexte non sécurisé).')
      return
    }
    setEditing((prev) => ({ ...prev, lat: coords.lat, lng: coords.lng }))
    setFlyTarget({ lat: coords.lat, lng: coords.lng, zoom: 15, nonce: Date.now() })
  }

  const saveDisabled =
    saveMut.isPending ||
    editing.lat === null ||
    editing.lng === null ||
    !editing.name.trim() ||
    editing.radius_m < MIN_RADIUS_M ||
    editing.radius_m > MAX_RADIUS_M

  return {
    zones,
    isLoading: zonesQuery.isLoading,
    editing,
    setEditing,
    flyTarget,
    setFlyTarget,
    handleMapClick,
    selectZone,
    handleDelete,
    useMyLocation,
    save: () =>
      saveMut.mutate({
        id: editing.id,
        payload: {
          name: editing.name.trim(),
          latitude: editing.lat,
          longitude: editing.lng,
          radius_m: Math.round(editing.radius_m),
          is_active: editing.is_active,
        },
      }),
    isSaving: saveMut.isPending,
    toggleActive: (zone, isActive) => toggleMut.mutate({ id: zone.id, is_active: isActive }),
    cancelEdit: () => setEditing(EMPTY_EDITING),
    saveDisabled,
    // Réglages globaux : état du contrôle, zones actives et bascule.
    geofenceEnabled: Boolean(settingsQuery.data?.geofence_enabled),
    activeZones: settingsQuery.data?.active_zones ?? 0,
    enforcedRoles: settingsQuery.data?.enforced_roles ?? [],
    isLoadingSettings: settingsQuery.isLoading,
    isTogglingGeofence: settingsMut.isPending,
    toggleGeofence: (enabled) => settingsMut.mutate(enabled),
  }
}
