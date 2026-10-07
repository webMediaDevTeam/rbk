import { Link2, Loader2, PhoneOff, RefreshCw, CloudDownload } from 'lucide-react'
import Badge from '@/components/ui/badge'
import Button from '@/components/ui/button'
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useCallLogs } from './useCallLogs.js'
import RecordingPlayer from './RecordingPlayer.jsx'

/** `2026-10-06T10:08:10Z` → `06/10 10:08`. */
function formatDate(value) {
  if (!value) return null

  const date = new Date(value)

  return Number.isNaN(date.getTime())
    ? null
    : date.toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })
}

/**
 * Onglet « Appels » : journal d'appels de l'employé (ADMIN / SUPER_ADMIN).
 *
 * La lecture est **locale** (`call_logs` — aucune API à l'ouverture) ;
 * « Synchroniser » récupère le journal RingCentral et l'écrit en base, et
 * l'enregistrement est lu via le proxy audio.
 *
 * @param {{ id: string }} props  identifiant de l'utilisateur
 */
export default function CallLogsCard({ id }) {
  const {
    source,
    rows,
    isLoading,
    isFetching,
    isError,
    error,
    isRateLimited,
    refetch,
    sync,
    isSyncing,
    syncAll,
    isSyncingAll,
    needsLinking,
  } = useCallLogs(id)

  const errorMessage =
    error?.response?.data?.error ??
    error?.response?.data?.message ??
    'Impossible de charger le journal des appels.'

  const lastSync = formatDate(source?.synced_at)
  const report = source?.sync ?? null
  const numbers = Array.isArray(source?.phone_numbers) ? source.phone_numbers : []
  const busy = isSyncing || isSyncingAll

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Appels</CardTitle>
        <CardDescription>
          Journal de l'employé — appels sortants / entrants et enregistrements, stockés localement.
        </CardDescription>
        <CardAction>
          <Button variant="default" size="sm" onClick={() => sync()} disabled={busy}>
            <CloudDownload className={`h-4 w-4 ${busy ? 'animate-spin' : ''}`} />
            Synchroniser
          </Button>
          <Button variant="outline" size="sm" onClick={refetch} disabled={isFetching || busy}>
            <RefreshCw className={`h-4 w-4 ${isFetching ? 'animate-spin' : ''}`} />
            Actualiser
          </Button>
        </CardAction>
      </CardHeader>

      <CardContent className="px-5 space-y-4">
        {source && (
          <div className="flex flex-wrap items-center gap-2 text-xs">
            <Badge variant="secondary">
              {source.device_id ? `Appareil : ${source.device_id}` : 'Appareil : —'}
            </Badge>
            <Badge variant="secondary">Numéro : {source.from_number ?? '—'}</Badge>
            <Badge variant="outline">
              Extension : {source.extension_number ?? source.extension_id ?? '—'}
            </Badge>
            {lastSync && <Badge variant="outline">Relié le {lastSync}</Badge>}
            {report && (
              <Badge variant="outline">
                Dernière synchro : {report.created ?? 0} ajouté(s) · {report.updated ?? 0} mis à jour
              </Badge>
            )}
            {numbers.length > 0 && (
              <Badge variant="outline">Numéros du poste : {numbers.join(' · ')}</Badge>
            )}
          </div>
        )}

        {isLoading ? (
          <div className="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground">
            <Loader2 className="h-4 w-4 animate-spin" /> Chargement du journal…
          </div>
        ) : isError ? (
          <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-border py-10 text-center">
            <p className="max-w-[36rem] text-sm text-muted-foreground">{errorMessage}</p>

            {needsLinking ? (
              <>
                <p className="max-w-[32rem] text-xs text-muted-foreground">
                  Cet employé n'est encore relié à aucun poste RingCentral : liez d'abord les
                  employés à leurs numéros, puis synchronisez son journal.
                </p>
                <Button variant="default" size="sm" onClick={() => syncAll()} disabled={busy}>
                  <Link2 className="h-4 w-4" />
                  Relier les employés aux numéros RingCentral
                </Button>
              </>
            ) : (
              <>
                {isRateLimited && (
                  <p className="text-xs text-muted-foreground">
                    Nouvel essai automatique dans ~30 s…
                  </p>
                )}
                <Button variant="outline" size="sm" onClick={refetch}>
                  Réessayer
                </Button>
              </>
            )}
          </div>
        ) : rows.length === 0 ? (
          <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-border py-10 text-center">
            <PhoneOff className="h-6 w-6 text-muted-foreground" />
            <p className="text-sm font-medium">Aucun appel enregistré</p>
            <p className="max-w-[26rem] text-xs text-muted-foreground">
              Le journal est vide tant qu'aucune synchronisation n'a été faite. Appuyez sur
              « Synchroniser » pour récupérer les appels RingCentral de cet employé.
            </p>
            <Button variant="default" size="sm" onClick={() => sync()} disabled={busy}>
              <CloudDownload className={`h-4 w-4 ${busy ? 'animate-spin' : ''}`} />
              Synchroniser
            </Button>
          </div>
        ) : (
          <div className="rounded-xl bg-card text-card-foreground shadow-sm overflow-hidden">
            <Table>
              <TableHeader>
                <TableRow className="bg-background hover:bg-background">
                  <TableHead>Date</TableHead>
                  <TableHead>Sens</TableHead>
                  <TableHead>De</TableHead>
                  <TableHead>Vers</TableHead>
                  <TableHead>Durée</TableHead>
                  <TableHead>Résultat</TableHead>
                  <TableHead>Enregistrement</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((row) => (
                  <TableRow key={row.key}>
                    <TableCell className="whitespace-nowrap text-muted-foreground">{row.date}</TableCell>
                    <TableCell>
                      <Badge variant={row.isOutbound ? 'outline' : 'secondary'}>{row.direction}</Badge>
                    </TableCell>
                    <TableCell className="font-mono text-xs">{row.from}</TableCell>
                    <TableCell className="font-mono text-xs">{row.to}</TableCell>
                    <TableCell className="text-muted-foreground">{row.duration}</TableCell>
                    <TableCell className="text-muted-foreground">{row.result}</TableCell>
                    <TableCell>
                      {row.recordingId ? (
                        <RecordingPlayer recordingId={row.recordingId} />
                      ) : (
                        <span className="text-xs text-muted-foreground">—</span>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </CardContent>
    </Card>
  )
}
