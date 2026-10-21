import { useEffect, useState } from 'react'
import L from 'leaflet'
import { Circle, MapContainer, Marker, TileLayer, useMap, useMapEvents } from 'react-leaflet'
import 'leaflet/dist/leaflet.css'
import { Crosshair, Search } from 'lucide-react'
import { MIN_RADIUS_M, MAX_RADIUS_M } from './useGpsPage.js'

/** Pointeau CSS pur (pas d'asset Leaflet : bundler-safe). */
const zoneIcon = L.divIcon({
  className: '',
  html: '<div class="gps-zone-pin"><span></span></div>',
  iconSize: [22, 22],
  iconAnchor: [11, 11],
})

/** Écoute le clic carte → place le cercle en édition. */
function ClickCapture({ onMapClick }) {
  useMapEvents({
    click(event) {
      onMapClick({ lat: event.latlng.lat, lng: event.latlng.lng })
    },
  })
  return null
}

/** Recentre la carte sur la dernière cible (`nonce` force le rejeu). */
function FlyController({ target }) {
  const map = useMap()

  useEffect(() => {
    if (target) {
      map.flyTo([target.lat, target.lng], target.zoom ?? 14, { duration: 0.8 })
    }
  }, [map, target])

  return null
}

/**
 * Zoom automatique « voir tout le cercle » : dès que le rayon en édition
 * (ou son déplacement) dépasse la vue affichée, la carte dézoome pour
 * contenir le cercle entier. Un zoom manuel par l'admin n'est jamais
 * corrigé : l'ajustement ne se déclenche que sur changement de
 * position/rayon.
 */
function EditingFit({ editing }) {
  const map = useMap()
  const { lat, lng } = editing
  const radius = Math.min(Math.max(Number(editing.radius_m) || 0, MIN_RADIUS_M), MAX_RADIUS_M)

  useEffect(() => {
    if (lat === null || lng === null) {
      return
    }
    // Bornes approchées du cercle (1° de latitude ≈ 111,32 km).
    const dLat = radius / 111320
    const dLng = radius / (111320 * Math.cos((lat * Math.PI) / 180))
    const circleBounds = L.latLngBounds([lat - dLat, lng - dLng], [lat + dLat, lng + dLng])

    if (!map.getBounds().contains(circleBounds)) {
      map.fitBounds(circleBounds, { padding: [40, 40], maxZoom: 16 })
    }
  }, [map, lat, lng, radius])

  return null
}

/**
 * « Utiliser ma position » + recherche de lieu (Nominatim OSM) posés en
 * bandeau au-dessus de la carte. La recherche est volontairement limitée :
 * ~1 requête / s maximum, usage admin uniquement (politique d'OSM).
 */
function MapToolbar({ onUseMyLocation, searching, query, setQuery }) {
  return (
    <div className="absolute top-2 left-2 right-2 z-[1200] flex flex-col gap-2">
      <div className="flex items-center justify-center gap-2">
        <div className="relative flex-1 max-w-sm">
          <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
          <input
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Chercher un lieu (ville, adresse…)"
            className="w-full rounded-xl bg-background/90 backdrop-blur px-3 py-1.5 pl-8 text-sm shadow-md outline-none transition placeholder:text-muted-foreground focus:ring-2 focus:ring-primary/30"
          />
          {searching && query.trim().length >= 3 && (
            <span className="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">…</span>
          )}
        </div>
        <button
          type="button"
          onClick={onUseMyLocation}
          title="Utiliser ma position actuelle"
          className="inline-flex items-center gap-1.5 rounded-xl bg-background/90 backdrop-blur px-2.5 py-1.5 text-sm shadow-md transition hover:bg-background"
        >
          <Crosshair className="h-4 w-4" />
          <span className="hidden md:inline">Ma position</span>
        </button>
      </div>
    </div>
  )
}

/**
 * Carte des zones : Leaflet + tuiles OSM. Les zones enregistrées sont des
 * cercles (vert = active, gris = inactive) ; le cercle en édition est bleu,
 * son pointeau déplaçable, et son rayon suit le curseur (50 m – 1 km).
 */
export default function ZoneMap({
  zones,
  editing,
  flyTarget,
  onMapClick,
  onEditingMove,
  setFlyTarget,
  onUseMyLocation,
}) {
  const [query, setQuery] = useState('')
  const [results, setResults] = useState([])
  const [searching, setSearching] = useState(false)

  // Recherche Nominatim (débouncée 400 ms, 5 résultats max). En dessous de
  // 3 caractères, les résultats sont masqués (visibleResults) — pas de setState
  // dans le corps de l'effet.
  useEffect(() => {
    const term = query.trim()
    if (term.length < 3) {
      return undefined
    }
    const handle = setTimeout(async () => {
      setSearching(true)
      try {
        const url = `https://nominatim.openstreetmap.org/search?format=json&limit=5&q=${encodeURIComponent(term)}`
        const response = await fetch(url, { headers: { Accept: 'application/json' } })
        const body = await response.json()
        setResults(
          (Array.isArray(body) ? body : []).map((r) => ({
            label: r.display_name,
            lat: Number(r.lat),
            lon: Number(r.lon),
          })),
        )
      } catch {
        setResults([])
      } finally {
        setSearching(false)
      }
    }, 400)
    return () => clearTimeout(handle)
  }, [query])

  const hasEditing = editing.lat !== null && editing.lng !== null
  const visibleResults = query.trim().length >= 3 ? results : []

  return (
    <div className="relative rounded-2xl shadow-sm overflow-hidden">
      <MapToolbar
        onUseMyLocation={onUseMyLocation}
        searching={searching}
        query={query}
        setQuery={setQuery}
      />
      <MapContainer
        center={[36.8065, 10.1815]}
        zoom={12}
        scrollWheelZoom
        className="h-[560px] w-full"
      >
        <TileLayer
          attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
          url="https://tile.openstreetmap.org/{z}/{x}/{y}.png"
        />
        <ClickCapture onMapClick={onMapClick} />
        <FlyController target={flyTarget} />
        <EditingFit editing={editing} />

        {zones.map((zone) => (
          <Circle
            key={`zone-${zone.id}`}
            center={[Number(zone.latitude), Number(zone.longitude)]}
            radius={zone.radius_m}
            pathOptions={{
              color: zone.is_active ? '#16a34a' : '#9ca3af',
              fillColor: zone.is_active ? '#16a34a' : '#9ca3af',
              fillOpacity: 0.12,
              weight: 2,
            }}
          />
        ))}

        {hasEditing && (
          <>
            <Circle
              center={[editing.lat, editing.lng]}
              radius={Math.min(Math.max(Number(editing.radius_m) || 0, MIN_RADIUS_M), MAX_RADIUS_M)}
              pathOptions={{ color: '#2563eb', fillColor: '#2563eb', fillOpacity: 0.15, weight: 2 }}
            />
            <Marker
              position={[editing.lat, editing.lng]}
              icon={zoneIcon}
              draggable
              eventHandlers={{
                dragend: (event) => {
                  const { lat, lng } = event.target.getLatLng()
                  onEditingMove({ lat, lng })
                },
              }}
            />
          </>
        )}
      </MapContainer>

      {visibleResults.length > 0 && (
        <ul className="absolute top-12 left-1/2 -translate-x-1/2 z-[1300] max-w-sm w-full rounded-xl bg-popover/95 backdrop-blur text-popover-foreground shadow-lg overflow-hidden">
          {visibleResults.map((r) => (
            <li key={`${r.lat}-${r.lon}`}>
              <button
                type="button"
                className="w-full px-3 py-2 text-left text-sm hover:bg-muted truncate"
                onClick={() => {
                  setResults([])
                  setQuery('')
                  setFlyTarget({ lat: r.lat, lng: r.lon, zoom: 14, nonce: Date.now() })
                }}
                title={r.label}
              >
                {r.label}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
