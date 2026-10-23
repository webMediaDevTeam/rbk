import { Loader2, CheckCheck } from 'lucide-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { getClientDuplicatesApi, markAllClientRepeteApi } from '@/api/shared.api.js'
import ClientStatus from '@/pages/shared/components/ClientStatus/index.jsx'
import RowLink from '@/pages/shared/components/RowLink/index.jsx'
import Button from '@/components/ui/button'

/**
 * Onglet « Numéros répétés » de la fiche client : la liste des **autres**
 * fiches partageant exactement le même `phone_normalized` (clé de chiffres
 * nationaux, insensible au formatage saisi) — la détection de doublons par le
 * numéro. La fiche courante est exclue côté serveur ; chaque ligne mène à la
 * fiche du doublon (`/prospects/{id}`). Le **statut** de chaque doublon (dont
 * `REPETE` quand il est déjà rattaché) se lit dans la colonne « Statut ».
 *
 * Le tableau est **informatif** : pas d'action rapide par ligne (les marquages
 * directs, non contrôlés, sont proscrits dans les tableaux). Une seule action
 * groupée en en-tête — « Tout marquer comme répété » — rattache en masse les
 * doublons **non encore reliés** du numéro à la fiche courante (le parent), en
 * une seule requête serveur.
 *
 * Une fiche sans numéro normalisé n'a aucun doublon détectable : message
 * d'état vide dédié.
 *
 * @param {string} clientId identifiant de la fiche consultée (= parent pressenti).
 */
export default function ClientRepeatsTab({ clientId }) {
  const queryClient = useQueryClient()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['client-duplicates', clientId],
    queryFn: () => getClientDuplicatesApi(clientId),
    staleTime: 1000 * 30,
    retry: false,
  })

  // Rattachement **en masse** : toutes les doublons non encore reliées du même
  // numéro pointent vers la fiche courante (une seule requête serveur).
  const markAllMutation = useMutation({
    mutationFn: () => markAllClientRepeteApi(clientId),
    onSuccess: (res) => {
      const marked = res?.data?.data?.marked ?? 0
      toast.success(
        marked > 0
          ? `${marked} fiche${marked > 1 ? 's' : ''} marquée${marked > 1 ? 's' : ''} comme répétée${marked > 1 ? 's' : ''}.`
          : 'Aucune nouvelle fiche à marquer.',
      )
      queryClient.invalidateQueries({ queryKey: ['client-duplicates', clientId] })
      // Les compteurs de statut (pastille « num Répétés ») bougent : on les
      // rafraîchit (le compteur de l'onglet aussi, même source de données).
      queryClient.invalidateQueries({ queryKey: ['prospect-kpis'] })
    },
    onError: (err) => {
      toast.error(err?.response?.data?.message || 'Impossible de marquer les fiches.')
    },
  })

  if (isLoading) {
    return (
      <div className="flex items-center gap-2 text-sm text-muted-foreground">
        <Loader2 className="h-4 w-4 animate-spin" />
        Chargement des numéros répétés…
      </div>
    )
  }

  if (isError) {
    return <p className="text-sm text-destructive">Impossible de charger les numéros répétés.</p>
  }

  const payload = data?.data
  const normalized = payload?.phone_normalized
  const duplicates = Array.isArray(payload?.duplicates) ? payload.duplicates : []

  if (!normalized) {
    return (
      <p className="text-sm text-muted-foreground">
        Cette fiche n'a pas de numéro de téléphone normalisé — aucun doublon détectable.
      </p>
    )
  }

  if (duplicates.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        Aucun autre client ne partage ce numéro.
      </p>
    )
  }

  const unlinkedCount = duplicates.filter((d) => d.parent_client_id == null).length

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-muted-foreground">
          {duplicates.length} autre{duplicates.length > 1 ? 's' : ''} fiche
          {duplicates.length > 1 ? 's' : ''} avec le même numéro normalisé{' '}
          <span className="font-mono text-foreground">({normalized})</span>.
        </p>

        {unlinkedCount > 0 && (
          <Button
            type="button"
            size="sm"
            variant="default"
            disabled={markAllMutation.isPending}
            onClick={() => markAllMutation.mutate()}
            title={`Relie les ${unlinkedCount} fiche(s) non encore rattachée(s) à cette fiche et les marque comme répétées`}
          >
            {markAllMutation.isPending ? (
              <Loader2 className="mr-1 h-3.5 w-3.5 animate-spin" />
            ) : (
              <CheckCheck className="mr-1 h-3.5 w-3.5" />
            )}
            Tout marquer comme répété ({unlinkedCount})
          </Button>
        )}
      </div>

      <div className="overflow-x-auto rounded-lg border border-border">
        <table className="w-full text-sm">
          <thead className="bg-muted/50 text-left text-xs uppercase tracking-wide text-muted-foreground">
            <tr>
              <th className="px-3 py-2 font-medium">Nom</th>
              <th className="px-3 py-2 font-medium">Répondant(s)</th>
              <th className="px-3 py-2 font-medium">Représentant</th>
              <th className="px-3 py-2 font-medium">Téléphone</th>
              <th className="px-3 py-2 font-medium">Municipalité</th>
              <th className="px-3 py-2 font-medium">Source</th>
              <th className="px-3 py-2 font-medium">Statut</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {duplicates.map((d) => (
              <tr key={d.id} className="hover:bg-muted/40">
                <td className="px-3 py-2">
                  {/* Doublon : le nom porte la fiche cible, même convention que
                      les tableaux de liste (`RowLink`). */}
                  <RowLink
                    to={`/prospects/${d.id}`}
                    title={`Voir la fiche de ${d.name ?? 'ce client'}`}
                    className="font-medium"
                  >
                    {d.name ?? '—'}
                  </RowLink>
                </td>
                <td className="px-3 py-2">
                  {(Array.isArray(d.respondents) ? d.respondents : [])
                    .map((r) => r?.name)
                    .filter(Boolean)
                    .join(', ') || '—'}
                </td>
                <td className="px-3 py-2">{d.representative_name || '—'}</td>
                <td className="px-3 py-2 font-mono">{d.phone ?? '—'}</td>
                <td className="px-3 py-2">{d.municipality ?? '—'}</td>
                <td className="px-3 py-2">{d.source ?? '—'}</td>
                <td className="px-3 py-2">
                  <ClientStatus
                    status={d.display_status ?? d.status}
                    isBlacklisted={d.is_blacklisted}
                  />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
