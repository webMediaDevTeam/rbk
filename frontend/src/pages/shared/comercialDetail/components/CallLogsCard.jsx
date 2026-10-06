import { Loader2, PhoneOff, RefreshCw } from 'lucide-react'
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

/**
 * Onglet « Appels » : journal RingCentral de l'employé (ADMIN / SUPER_ADMIN),
 * résolu côté API **via son appareil** (`ringcentral_device_id`), avec
 * lecture de l'enregistrement quand la ligne en porte un.
 *
 * @param {{ id: string }} props  identifiant de l'utilisateur
 */
export default function CallLogsCard({ id }) {
  const { source, rows, isLoading, isFetching, isError, error, refetch } = useCallLogs(id)

  const errorMessage =
    error?.response?.data?.error ??
    error?.response?.data?.message ??
    'Impossible de charger le journal des appels.'

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Appels</CardTitle>
        <CardDescription>
          Journal RingCentral de l'employé — appels sortants / entrants et enregistrements.
        </CardDescription>
        <CardAction>
          <Button variant="outline" size="sm" onClick={refetch} disabled={isFetching}>
            <RefreshCw className={`h-4 w-4 ${isFetching ? 'animate-spin' : ''}`} />
            Actualiser
          </Button>
        </CardAction>
      </CardHeader>

      <CardContent className="px-5 space-y-4">
        {source && (
          <div className="flex flex-wrap items-center gap-2 text-xs">
            <Badge variant="secondary">Appareil : {source.device_id ?? '—'}</Badge>
            <Badge variant="secondary">Numéro : {source.from_number ?? '—'}</Badge>
            <Badge variant="outline">
              Extension : {source.extension_number ?? source.extension_id ?? '—'}
            </Badge>
            {source.filtered_by_device && <Badge variant="outline">Filtré par appareil</Badge>}
          </div>
        )}

        {isLoading ? (
          <div className="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground">
            <Loader2 className="h-4 w-4 animate-spin" /> Chargement du journal…
          </div>
        ) : isError ? (
          <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-border py-10 text-center">
            <p className="max-w-[36rem] text-sm text-muted-foreground">{errorMessage}</p>
            <Button variant="outline" size="sm" onClick={refetch}>
              Réessayer
            </Button>
          </div>
        ) : rows.length === 0 ? (
          <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-border py-10 text-center">
            <PhoneOff className="h-6 w-6 text-muted-foreground" />
            <p className="text-sm font-medium">Aucun appel</p>
            <p className="max-w-[24rem] text-xs text-muted-foreground">
              Aucun appel enregistré chez RingCentral pour cet employé (ou aucune extension rattachée
              à sa fiche).
            </p>
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
