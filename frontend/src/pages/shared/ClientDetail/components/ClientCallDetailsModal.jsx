import { useQuery } from '@tanstack/react-query'
import { Loader2, X } from 'lucide-react'
import RecordingPlayer from '@/components/RecordingPlayer.jsx'
import { getClientCallDetailsApi, getClientCallRecordingApi } from '@/api/commercial.api.js'

function formatDate(value) {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('fr-CA')
}

function formatDuration(value) {
  if (value == null) return '—'
  const seconds = Math.max(0, Number(value) || 0)
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

export default function ClientCallDetailsModal({ callLogId, onClose }) {
  const query = useQuery({
    queryKey: ['client-call-details', callLogId],
    queryFn: () => getClientCallDetailsApi(callLogId),
    enabled: Boolean(callLogId),
    retry: false,
  })
  const call = query.data?.data ?? null
  const events = Array.isArray(call?.events) ? call.events : []
  const recordings = Array.isArray(call?.recordings) ? call.recordings : []

  return (
    <div className="fixed inset-0 z-100 flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="client-call-dialog-title"
        className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl border border-border bg-card p-6 text-card-foreground shadow-xl"
        onClick={(event) => event.stopPropagation()}
      >
        <header className="mb-5 flex items-center justify-between gap-4">
          <h2 id="client-call-dialog-title" className="text-lg font-semibold">Détails de l’appel</h2>
          <button type="button" onClick={onClose} className="rounded-md p-1 hover:bg-muted" aria-label="Fermer">
            <X className="h-4 w-4" />
          </button>
        </header>

        {query.isLoading ? (
          <div className="flex items-center justify-center gap-2 py-12 text-sm text-muted-foreground">
            <Loader2 className="h-4 w-4 animate-spin" /> Chargement des informations de l’appel…
          </div>
        ) : query.isError ? (
          <p role="alert" className="py-8 text-center text-sm text-destructive">
            Impossible de charger les informations ou le journal de l’appel.
          </p>
        ) : call ? (
          <div className="space-y-6">
            <dl className="grid grid-cols-1 gap-4 border-b border-border pb-5 sm:grid-cols-2">
              <div><dt className="text-xs text-muted-foreground">Client</dt><dd className="mt-1 text-sm font-medium">{call.client?.name ?? '—'}</dd></div>
              <div><dt className="text-xs text-muted-foreground">Téléphone</dt><dd className="mt-1 text-sm font-medium">{call.phone_number ?? call.client?.phone ?? '—'}</dd></div>
              <div><dt className="text-xs text-muted-foreground">Date</dt><dd className="mt-1 text-sm font-medium">{formatDate(call.started_at)}</dd></div>
              <div><dt className="text-xs text-muted-foreground">Statut</dt><dd className="mt-1 text-sm font-medium">{call.status ?? '—'}</dd></div>
              <div><dt className="text-xs text-muted-foreground">Durée</dt><dd className="mt-1 text-sm font-medium">{formatDuration(call.duration)}</dd></div>
              <div><dt className="text-xs text-muted-foreground">ID RingCentral</dt><dd className="mt-1 break-all font-mono text-xs">{call.ringcentral_call_id ?? call.session_id ?? '—'}</dd></div>
            </dl>

            <section aria-labelledby="call-events-title">
              <h3 id="call-events-title" className="mb-3 text-sm font-semibold">Journal d’appel</h3>
              {events.length ? (
                <ol className="space-y-3 border-l border-border pl-4">
                  {events.map((event, index) => (
                    <li key={event.id ?? `${event.time ?? event.timestamp ?? 'event'}-${index}`} className="text-sm">
                      <span className="mr-2 text-xs text-muted-foreground">{formatDate(event.time ?? event.timestamp ?? event.date)}</span>
                      <span>{event.description ?? event.message ?? event.type ?? event.status ?? 'Événement'}</span>
                    </li>
                  ))}
                </ol>
              ) : (
                <p className="text-sm text-muted-foreground">Aucun événement supplémentaire disponible.</p>
              )}
            </section>

            <section aria-labelledby="call-recording-title">
              <h3 id="call-recording-title" className="mb-3 text-sm font-semibold">Enregistrement</h3>
              {recordings.length ? (
                <div className="space-y-3">
                  {recordings.map((recording) => (
                    <RecordingPlayer
                      key={recording.id}
                      load={() => getClientCallRecordingApi(call.id, recording.id)}
                      type={recording.type}
                      recordingId={recording.id}
                      duration={recording.duration}
                    />
                  ))}
                </div>
              ) : (
                <p className="text-sm text-muted-foreground">Aucun enregistrement disponible.</p>
              )}
            </section>
          </div>
        ) : null}
      </section>
    </div>
  )
}