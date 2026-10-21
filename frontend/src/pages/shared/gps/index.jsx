import { Pencil, Trash2, X } from 'lucide-react'
import Button from '@/components/ui/button.jsx'
import Switch from '@/components/ui/switch.jsx'
import { useGpsPage, MIN_RADIUS_M, MAX_RADIUS_M } from './useGpsPage.js'
import ZoneMap from './ZoneMap.jsx'

/**
 * Page « GPS » (ADMIN / SUPER_ADMIN) : cercles de géorepérage appliqués à la
 * connexion des rôles contraints (COMERCIAL / OPERATOR) côté serveur — voir
 * `GeofenceService`. Un commercial avec la dérogation « Connexion hors zones »
 * (page Employés) n'est jamais bloqué.
 */
export default function GpsPage() {
  const {
    zones,
    isLoading,
    editing,
    setEditing,
    flyTarget,
    setFlyTarget,
    handleMapClick,
    selectZone,
    handleDelete,
    useMyLocation,
    save,
    isSaving,
    toggleActive,
    cancelEdit,
    saveDisabled,
    geofenceEnabled,
    activeZones,
    enforcedRoles,
    isLoadingSettings,
    isTogglingGeofence,
    toggleGeofence,
  } = useGpsPage()

  const isEditing = editing.lat !== null || editing.name !== '' || editing.id !== null

  // Rôles contraints, formulés en français (« commerciaux et opérateurs »).
  const enforcedLabel = new Intl.ListFormat('fr', { type: 'conjunction' }).format(
    enforcedRoles.map((r) => (r === 'COMERCIAL' ? 'les commerciaux' : r === 'OPERATOR' ? 'les opérateurs' : r.toLowerCase())),
  )

  return (
    <div className="max-w-7xl mx-auto space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-foreground">Zones GPS</h1>
        <p className="text-sm text-muted-foreground mt-1">
          Le contrôle de la connexion se règle ci-dessous. Une fois activé, les
          commerciaux et opérateurs ne peuvent se connecter que depuis une zone
          active — sauf dérogation « Connexion hors zones » (page Employés).
          Cliquez sur la carte pour poser un cercle, faites glisser le pointeau
          pour le déplacer.
        </p>
      </div>

      {/* ── Coupe-circuit **global** du contrôle GPS ──────────────────────
          Réglé en base (et non par `GEOFENCE_ENABLED`) : il se bascule ici,
          sans redéploiement. **Éteint par défaut** : personne n'est jamais
          bloqué à la connexion et la page « Connexion » ne demande plus la
          position au navigateur. Allumé, les rôles contraints sans dérogation
          doivent se trouver dans une zone active. */}
      <section className="rounded-2xl bg-card p-4 text-card-foreground shadow-sm sm:p-5">
        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
          <div className="min-w-0">
            <h2 className="text-sm font-semibold">Contrôle GPS à la connexion</h2>
            <p className="mt-1 text-xs text-muted-foreground">
              {geofenceEnabled
                ? `Actif — ${enforcedLabel} ne peuvent se connecter que depuis une zone active (sauf dérogation).`
                : "Inactif — personne n'est bloqué à la connexion, et le navigateur n'est plus interrogé."}
            </p>
          </div>

          <div className="flex items-center gap-3">
            <span className={`text-xs font-semibold ${geofenceEnabled ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground'}`}>
              {geofenceEnabled ? 'Activé' : 'Désactivé'}
            </span>
            <Switch
              id="gps-geofence-enabled"
              checked={geofenceEnabled}
              onCheckedChange={toggleGeofence}
              disabled={isLoadingSettings || isTogglingGeofence}
              aria-label="Activer le contrôle GPS à la connexion"
            />
          </div>
        </div>

        {/* Garde-fou : activer le contrôle sans aucune zone valide refuserait
            toute connexion des rôles contraints. */}
        {geofenceEnabled && activeZones === 0 ? (
          <p className="mt-3 rounded-xl bg-destructive/10 px-3 py-2 text-xs text-destructive">
            Aucune zone active : {enforcedLabel} ne pourraient plus se connecter du tout.
            Créez une zone et laissez son interrupteur allumé avant de laisser ce contrôle activé.
          </p>
        ) : null}
      </section>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div className="lg:col-span-2">
          <ZoneMap
            zones={zones}
            editing={editing}
            flyTarget={flyTarget}
            onMapClick={handleMapClick}
            onEditingMove={({ lat, lng }) => setEditing((prev) => ({ ...prev, lat, lng }))}
            setFlyTarget={setFlyTarget}
            onUseMyLocation={useMyLocation}
          />
        </div>

        <div className="space-y-4">
          {isEditing && (
            <div className="rounded-2xl bg-card p-4 space-y-4 shadow-sm">
              <div className="flex items-center justify-between">
                <h2 className="text-sm font-semibold">
                  {editing.id ? 'Modifier la zone' : 'Nouvelle zone'}
                </h2>
                <button
                  type="button"
                  onClick={cancelEdit}
                  className="text-muted-foreground hover:text-foreground"
                  aria-label="Annuler l'édition"
                >
                  <X className="h-4 w-4" />
                </button>
              </div>

              <div className="space-y-1.5">
                <label htmlFor="gps-zone-name" className="text-xs font-medium text-muted-foreground">
                  Nom de la zone
                </label>
                <input
                  id="gps-zone-name"
                  value={editing.name}
                  onChange={(e) => setEditing((prev) => ({ ...prev, name: e.target.value }))}
                  placeholder="Ex. : Bureau Tunis"
                  className="w-full rounded-lg bg-muted/60 px-3 py-2 text-sm outline-none transition placeholder:text-muted-foreground focus:bg-muted/80 focus:ring-2 focus:ring-primary/30"
                />
              </div>

              <div className="space-y-1.5">
                <label htmlFor="gps-zone-radius" className="text-xs font-medium text-muted-foreground">
                  Rayon : {editing.radius_m} m
                </label>
                <div className="flex items-center gap-2">
                  <input
                    id="gps-zone-radius"
                    type="range"
                    min={MIN_RADIUS_M}
                    max={MAX_RADIUS_M}
                    step={50}
                    value={editing.radius_m}
                    onChange={(e) => setEditing((prev) => ({ ...prev, radius_m: Number(e.target.value) }))}
                    className="flex-1 accent-primary"
                  />
                  <input
                    type="number"
                    min={MIN_RADIUS_M}
                    max={MAX_RADIUS_M}
                    value={editing.radius_m}
                    onChange={(e) => setEditing((prev) => ({ ...prev, radius_m: Number(e.target.value) }))}
                    className="w-24 rounded-lg bg-muted/60 px-2.5 py-1.5 text-sm tabular-nums outline-none transition focus:bg-muted/80 focus:ring-2 focus:ring-primary/30"
                  />
                </div>
              </div>

              <label className="flex items-center justify-between text-sm">
                <span className="text-muted-foreground">Zone active</span>
                <Switch
                  checked={editing.is_active}
                  onCheckedChange={(checked) => setEditing((prev) => ({ ...prev, is_active: checked }))}
                />
              </label>

              {editing.lat !== null && (
                <p className="text-xs text-muted-foreground tabular-nums">
                  Position : {editing.lat.toFixed(5)}, {editing.lng.toFixed(5)}
                </p>
              )}

              <div className="flex items-center gap-2">
                <Button size="sm" onClick={save} disabled={saveDisabled}>
                  {isSaving ? 'Enregistrement…' : 'Enregistrer'}
                </Button>
                <Button size="sm" variant="outline" onClick={cancelEdit}>
                  Annuler
                </Button>
              </div>
            </div>
          )}

          <div className="rounded-2xl bg-card shadow-sm overflow-hidden">
            <div className="px-4 py-3 bg-muted/40">
              <h2 className="text-sm font-semibold">Zones ({zones.length})</h2>
            </div>
            {isLoading ? (
              <div className="p-4 text-sm text-muted-foreground">Chargement…</div>
            ) : zones.length === 0 ? (
              <div className="p-4 text-sm text-muted-foreground">
                Aucune zone — cliquez sur la carte pour en créer une.
              </div>
            ) : (
              <ul className="divide-y divide-border/40">
                {zones.map((zone) => (
                  <li
                    key={zone.id}
                    className={`flex items-center gap-2 px-3 py-2.5 transition-colors ${
                      editing.id === zone.id ? 'bg-primary/5' : 'hover:bg-muted/40'
                    }`}
                  >
                    <span
                      className={`h-2 w-2 shrink-0 rounded-full ${
                        zone.is_active ? 'bg-green-500' : 'bg-muted-foreground/40'
                      }`}
                      aria-hidden
                    />
                    <div className="flex-1 min-w-0">
                      <button
                        type="button"
                        onClick={() => selectZone(zone)}
                        className="block w-full text-left truncate text-sm font-medium hover:underline"
                        title={`Aller vers « ${zone.name} »`}
                      >
                        {zone.name}
                      </button>
                      <span className="text-xs text-muted-foreground">
                        {zone.radius_m} m — {Number(zone.latitude).toFixed(4)}, {Number(zone.longitude).toFixed(4)}
                      </span>
                    </div>
                    <Switch
                      checked={Boolean(zone.is_active)}
                      onCheckedChange={(checked) => toggleActive(zone, checked)}
                      aria-label={`Activer la zone ${zone.name}`}
                    />
                    <button
                      type="button"
                      onClick={() => selectZone(zone)}
                      className="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground hover:bg-muted"
                      title="Modifier"
                    >
                      <Pencil className="h-3.5 w-3.5" />
                    </button>
                    <button
                      type="button"
                      onClick={() => handleDelete(zone)}
                      className="inline-flex h-7 w-7 items-center justify-center rounded-md text-destructive hover:bg-destructive/10"
                      title="Supprimer"
                    >
                      <Trash2 className="h-3.5 w-3.5" />
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
