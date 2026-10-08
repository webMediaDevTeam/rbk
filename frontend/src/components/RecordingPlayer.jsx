import { useCallback, useEffect, useRef, useState } from 'react'
import { Download, Pause, Play, RotateCcw, Volume2, VolumeX } from 'lucide-react'
import WaveSurfer from 'wavesurfer.js'

/**
 * Lecteur d'un enregistrement RingCentral — forme d'onde + contrôles.
 *
 * Le `contentUri` exige l'en-tête `Authorization`, qu'un `<audio>` ne sait
 * pas envoyer : on récupère donc le flux **avec** le token via le proxy
 * backend (`load()` → `Blob`), puis on le rend avec wavesurfer.js —
 * forme d'onde cliquable/déplaçable, vitesse de lecture, volume et
 * téléchargement.
 *
 * @param {{
 *   load: () => Promise<Blob>,
 *   lazy?: boolean,      // « Écouter » d'abord (lignes de tableau) vs auto
 *   compact?: boolean,   // variante étroite : ni bandeau, ni vitesse/volume
 *   type?: string|null,
 *   recordingId?: string|null,
 *   duration?: number|null,
 * }} props
 */
export default function RecordingPlayer({
  load,
  lazy = false,
  compact = false,
  type = null,
  recordingId = null,
  duration: recordingDuration = null,
}) {
  const [requested, setRequested] = useState(!lazy)
  const [blob, setBlob] = useState(null)
  const [failed, setFailed] = useState(false)
  const [retry, setRetry] = useState(0)
  const [isPlaying, setIsPlaying] = useState(false)
  const [currentTime, setCurrentTime] = useState(0)
  const [duration, setDuration] = useState(Number(recordingDuration) || 0)
  const [rate, setRate] = useState(1)
  const [muted, setMuted] = useState(false)
  const [volume, setVolume] = useState(1)
  const [hoverRatio, setHoverRatio] = useState(null)

  const waveRef = useRef(null)
  const surfRef = useRef(null)
  const loadRef = useRef(load)

  const height = compact ? 44 : 84

  // `load` change à chaque rendu (flèche inline) : on le fige dans une ref
  // pour que la boucle de chargement ne se relance pas en boucle.
  useEffect(() => {
    loadRef.current = load
  }, [load])

  // Dérivé : `idle` (lazy, pas encore demandé) | `loading` | `ready` | `error`.
  const status = !requested ? 'idle' : failed ? 'error' : blob ? 'ready' : 'loading'

  // 1. Flux audio (proxy backend, en-tête Authorization).
  useEffect(() => {
    if (!requested) return undefined

    let cancelled = false

    loadRef.current()
      .then((data) => {
        if (cancelled) return
        setBlob(data)
        setFailed(false)
      })
      .catch(() => {
        if (cancelled) return
        setBlob(null)
        setFailed(true)
      })

    return () => {
      cancelled = true
    }
  }, [requested, retry])

  // 2. Forme d'onde (créée une fois le Blob disponible).
  useEffect(() => {
    if (!blob || !waveRef.current) return undefined

    let disposed = false
    const container = waveRef.current
    const gradient = document.createElement('canvas').getContext('2d')
      ?.createLinearGradient(0, 0, Math.max(container.clientWidth, 1), 0)

    if (gradient) {
      gradient.addColorStop(0, '#a21caf') // --color-primary
      gradient.addColorStop(1, '#d946ef') // --color-ring
    }

    const surf = WaveSurfer.create({
      container,
      height,
      barWidth: 3,
      barGap: 2,
      barRadius: 3,
      normalize: true,
      dragToSeek: true,
      waveColor: '#e9d5ff',
      progressColor: gradient ?? '#a21caf',
      cursorColor: '#701a75',
      cursorWidth: 2,
    })

    surf.on('ready', (seconds) => {
      if (disposed) return
      setDuration(seconds)
      setCurrentTime(0)
    })
    surf.on('timeupdate', (seconds) => {
      if (!disposed) setCurrentTime(seconds)
    })
    surf.on('seeking', (seconds) => {
      if (!disposed) setCurrentTime(seconds)
    })
    surf.on('play', () => {
      if (!disposed) setIsPlaying(true)
    })
    surf.on('pause', () => {
      if (!disposed) setIsPlaying(false)
    })
    surf.on('finish', () => {
      if (disposed) return
      setIsPlaying(false)
      setCurrentTime(surf.getDuration())
    })
    surf.on('error', () => {
      if (!disposed) setFailed(true)
    })

    surfRef.current = surf
    surf.loadBlob(blob).catch((error) => {
      // Un destroy pendant le chargement rejette en AbortError : normal.
      if (!disposed && error?.name !== 'AbortError') setFailed(true)
    })

    return () => {
      disposed = true
      surf.destroy()
      surfRef.current = null
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- recréée pour chaque Blob
  }, [blob])

  // 3. Réglages appliqués à l'instance courante.
  useEffect(() => {
    const surf = surfRef.current
    if (!surf) return
    surf.setPlaybackRate(rate)
    surf.setMuted(muted)
    surf.setVolume(volume)
  }, [rate, muted, volume, blob])

  const hasEnded = status === 'ready' && duration > 0 && currentTime >= duration - 0.05

  const togglePlay = useCallback(() => {
    const surf = surfRef.current
    if (!surf) return
    if (hasEnded) surf.seekTo(0)
    surf.playPause()
  }, [hasEnded])

  const restart = () => {
    surfRef.current?.seekTo(0)
    surfRef.current?.play()
  }

  const cycleSpeed = () => setRate((current) => SPEEDS[(SPEEDS.indexOf(current) + 1) % SPEEDS.length])

  const download = () => {
    if (!blob) return
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `enregistrement-${recordingId ?? 'appel'}.${EXTENSIONS[blob.type] ?? 'mp3'}`
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.setTimeout(() => URL.revokeObjectURL(url), 1000)
  }

  const typeLabel = type === 'Automatic'
    ? 'Automatique'
    : type === 'Manual'
      ? 'Manuel'
      : (type ?? 'Enregistrement')

  if (status === 'idle') {
    return (
      <button
        type="button"
        onClick={() => setRequested(true)}
        className="inline-flex items-center gap-1.5 rounded-lg border border-border px-2.5 py-1 text-xs font-medium transition-colors hover:bg-muted"
      >
        <Play className="h-3.5 w-3.5" />
        Écouter
      </button>
    )
  }

  if (status === 'error') {
    return (
      <div className="flex flex-wrap items-center gap-2 text-xs">
        <p role="alert" className="text-destructive">Enregistrement indisponible.</p>
        <button
          type="button"
          onClick={() => {
            setBlob(null)
            setFailed(false)
            setRetry((value) => value + 1)
          }}
          className="rounded-lg border border-border px-2.5 py-1 font-medium transition-colors hover:bg-muted"
        >
          Réessayer
        </button>
      </div>
    )
  }

  return (
    <div className={compact ? 'w-full min-w-32' : 'rounded-xl border border-border bg-card p-3 shadow-sm sm:p-4'}>
      {!compact && (
        <div className="mb-3 flex items-center justify-between gap-3">
          <div className="flex min-w-0 items-center gap-2">
            <span className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-1 text-[11px] font-semibold text-primary">
              <span className="h-1.5 w-1.5 rounded-full bg-primary" />
              {typeLabel}
            </span>
            {recordingId && <span className="truncate font-mono text-[11px] text-muted-foreground">{recordingId}</span>}
          </div>
          {duration > 0 && (
            <span className="shrink-0 font-mono text-[11px] text-muted-foreground">{formatTime(duration)}</span>
          )}
        </div>
      )}

      <div
        className="relative w-full select-none"
        style={{ height }}
        onMouseMove={(event) => {
          const rect = event.currentTarget.getBoundingClientRect()
          setHoverRatio(Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width)))
        }}
        onMouseLeave={() => setHoverRatio(null)}
      >
        <div ref={waveRef} className="absolute inset-0" />

        {status === 'loading' && (
          <div className="absolute inset-0 flex items-center gap-1 px-1" aria-hidden>
            {SKELETON_BARS.map((bar, index) => (
              <span
                key={index}
                className="recording-eq__bar flex-1 rounded-full bg-primary/25"
                style={{ height: `${bar}%`, animationDelay: `${(index % 7) * 90}ms` }}
              />
            ))}
          </div>
        )}

        {hoverRatio !== null && duration > 0 && status === 'ready' && (
          <div className="pointer-events-none absolute inset-y-0" style={{ left: `${hoverRatio * 100}%` }}>
            <div className="h-full w-px -translate-x-1/2 bg-foreground/30" />
            <span className="absolute top-1 left-1/2 -translate-x-1/2 rounded bg-foreground px-1.5 py-0.5 font-mono text-[10px] font-medium text-background">
              {formatTime(hoverRatio * duration)}
            </span>
          </div>
        )}
      </div>

      <div className="mt-3 flex items-center gap-2 sm:gap-3">
        <button
          type="button"
          onClick={togglePlay}
          disabled={status !== 'ready'}
          aria-label={isPlaying ? 'Mettre en pause' : 'Lire'}
          className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary text-primary-foreground shadow-sm transition hover:brightness-110 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring active:scale-95 disabled:opacity-60"
        >
          {isPlaying
            ? <Pause className="h-4 w-4" />
            : <Play className="h-4 w-4 translate-x-[1px]" />}
        </button>

        <span className="font-mono text-xs tabular-nums text-muted-foreground">
          <span className="text-foreground">{formatTime(currentTime)}</span>
          {' / '}
          {formatTime(duration)}
        </span>

        {isPlaying && (
          <span aria-hidden className="hidden h-4 items-end gap-0.5 sm:flex">
            {[0, 1, 2].map((bar) => (
              <span key={bar} className="recording-eq__bar h-full w-1 rounded-full bg-primary" />
            ))}
          </span>
        )}

        <div className="ml-auto flex items-center gap-1.5 sm:gap-2">
          {!compact && (
            <button
              type="button"
              onClick={cycleSpeed}
              aria-label={`Vitesse de lecture : ${rate}×`}
              className="min-w-11 rounded-lg border border-border px-2 py-1.5 font-mono text-xs font-semibold transition-colors hover:bg-muted"
            >
              {rate}×
            </button>
          )}

          {!compact && (
            <button
              type="button"
              onClick={() => setMuted((value) => !value)}
              aria-label={muted ? 'Rétablir le son' : 'Couper le son'}
              aria-pressed={muted}
              className="grid h-8 w-8 place-items-center rounded-lg border border-border transition-colors hover:bg-muted"
            >
              {muted ? <VolumeX className="h-4 w-4" /> : <Volume2 className="h-4 w-4" />}
            </button>
          )}

          {!compact && (
            <input
              type="range"
              min="0"
              max="1"
              step="0.05"
              value={muted ? 0 : volume}
              onChange={(event) => {
                const value = Number(event.target.value)
                setVolume(value)
                if (value > 0) setMuted(false)
              }}
              aria-label="Volume"
              className="hidden w-20 accent-primary sm:block"
            />
          )}

          {hasEnded && (
            <button
              type="button"
              onClick={restart}
              aria-label="Revenir au début"
              className="grid h-8 w-8 place-items-center rounded-lg border border-border transition-colors hover:bg-muted"
            >
              <RotateCcw className="h-4 w-4" />
            </button>
          )}

          <button
            type="button"
            onClick={download}
            aria-label="Télécharger l'enregistrement"
            className="grid h-8 w-8 place-items-center rounded-lg border border-border transition-colors hover:bg-muted"
          >
            <Download className="h-4 w-4" />
          </button>
        </div>
      </div>
    </div>
  )
}

/** Vitesses de lecture proposées (boucle du bouton « 1× »). */
const SPEEDS = [0.75, 1, 1.25, 1.5, 2]

/** Hauteurs (en %) du squelette affiché pendant le décodage du flux. */
const SKELETON_BARS = [35, 62, 48, 78, 40, 66, 30, 55, 72, 45, 60, 38, 70, 50, 42, 64, 36, 74, 52, 44]

/** Extension de fichier selon le MIME renvoyé par le proxy backend. */
const EXTENSIONS = {
  'audio/mpeg': 'mp3',
  'audio/wav': 'wav',
  'audio/ogg': 'ogg',
  'audio/mp4': 'm4a',
  'audio/aac': 'aac',
}

function formatTime(value) {
  const total = Math.max(0, Math.floor(Number(value) || 0))
  const seconds = total % 60
  const minutes = Math.floor(total / 60)

  if (minutes >= 60) {
    return `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
  }

  return `${minutes}:${String(seconds).padStart(2, '0')}`
}
