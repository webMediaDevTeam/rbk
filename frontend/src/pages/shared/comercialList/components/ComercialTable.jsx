import { Link } from 'react-router-dom'
import { AlertTriangle, Eye, ImagePlus, Pencil, Power, Trash2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import Badge from '@/components/ui/badge'
import Switch from '@/components/ui/switch.jsx'
import StatusBadge from './StatusBadge'
import RowMenu from '@/pages/shared/components/RowMenu/index.jsx'
import SortHeader from '@/pages/shared/components/SortHeader/index.jsx'
import UserAvatar from '@/pages/shared/components/UserAvatar/index.jsx'
import RowLink from '@/pages/shared/components/RowLink/index.jsx'
import ComercialStatsChips from './ComercialStatsChips.jsx'
import { useComercialTable } from './useComercialTable.js'

export default function ComercialTable(props) {
  const { rows, sortBy, sortOrder, onSort, canDelete } = useComercialTable(props)

  return (
    <div className="rounded-xl bg-card text-card-foreground shadow-sm overflow-hidden">
      <Table>
        <TableHeader>
          <TableRow className="bg-background hover:bg-background">
            <TableHead>
              <SortHeader column="name" currentSortBy={sortBy} sortOrder={sortOrder} onSort={onSort}>Employé</SortHeader>
            </TableHead>
            <TableHead>N° RingCentral</TableHead>
            <TableHead>Entreprise</TableHead>
            
            <TableHead title="Privilège de libération (§7.1) — autorise ce commercial à libérer ses réservations en attente">
              <span className="inline-flex items-center gap-1">Privilège</span>
            </TableHead>
            <TableHead title="Autoriser la connexion en dehors des zones GPS — 24 h après activation, puis retrait automatique">
              <span className="inline-flex items-center gap-1">Hors zones (24 h)</span>
            </TableHead>
            <TableHead>
              <SortHeader column="status" currentSortBy={sortBy} sortOrder={sortOrder} onSort={onSort}>Statut</SortHeader>
            </TableHead>
            <TableHead className="w-10" />
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((row) => (
            <TableRow key={row.key}>
              <TableCell>
                <div className="flex items-center gap-2.5">
                  <UserAvatar user={row.c} size="sm" onEdit={row.handleAvatarClick} />
                  <div className="flex flex-col leading-tight">
                    <span className="font-medium flex items-center gap-1.5">
                      {/* Nom = fiche employé, même cible que l'œil « Voir » — un
                          vrai lien, ouvrable en autre onglet. L'opérateur n'a pas
                          de fiche : il reste sur texte simple. */}
                      {row.isOperator ? row.name : (
                        <RowLink
                          to={`/comercialDetail/${row.c.id}`}
                          title={`Voir la fiche de ${row.name}`}
                        >
                          {row.name}
                        </RowLink>
                      )}
                      {/* Rôle — « Opérateur » badgeé pour le distinguer d'un
                          coup d'œil (le commercial reste l'implicite). */}
                      {row.isOperator && (
                        <Badge variant="info" className="uppercase">Opérateur</Badge>
                      )}
                    </span>
                    <span className="text-xs text-muted-foreground">{row.c.email}</span>
                  </div>
                </div>
              </TableCell>
              <TableCell className="text-muted-foreground">
                {row.rcNumber ? (
                  <span className="inline-flex items-center gap-1.5">
                    <span className={`tabular-nums ${row.rcDuplicate ? 'font-semibold text-red-600' : ''}`}>{row.rcNumber}</span>
                    {row.rcDuplicate && (
                      <span
                        className="inline-flex animate-bounce items-center gap-1 rounded-md bg-red-500/15 px-1.5 py-0.5 text-xs font-semibold text-red-600 ring-1 ring-red-500/40"
                        title="Ce numéro RingCentral est partagé avec un autre employé — corrigez la source d'appel"
                      >
                        <AlertTriangle className="h-3.5 w-3.5" /> Doublon
                      </span>
                    )}
                  </span>
                ) : (
                  '—'
                )}
              </TableCell>
              <TableCell className="text-muted-foreground">
                <div className="flex flex-col gap-1">
                  <span>{row.enterpriseName}</span>
                  {row.enterpriseSource && (
                    <Badge variant="secondary" className="w-fit" title={`Source de l'entreprise : ${row.enterpriseSource}`}>
                      {row.enterpriseSource}
                    </Badge>
                  )}
                </div>
              </TableCell>
             
              {/* Privilège commercial : même switch inline que la colonne
                  « Hors zones ». Réservé au COMERCIAL (un gestionnaire passe
                  toujours, un opérateur n'a rien à libérer). */}
              <TableCell>
                {row.c.role === 'COMERCIAL' ? (
                  <Switch
                    checked={row.hasPermission}
                    disabled={!canDelete}
                    onCheckedChange={(value) => row.handleTogglePrivilege(value)}
                    aria-label={`Privilège de libération pour ${row.name}`}
                  />
                ) : (
                  <span className="text-muted-foreground">-</span>
                )}
              </TableCell>
              <TableCell>
                {/* Dérogation GPS (roles contraints uniquement — un
                    opérateur / commercial peut se connecter hors zone si
                    activée). `canDelete` porte l'apparence de modification.
                    Accord **limité à 24 h** : le libellé montre le temps
                    restant, le serveur refuse déjà dès l'échéance passée. */}
                <div className="flex flex-col gap-0.5">
                  <Switch
                    checked={row.gpsBypass}
                    disabled={!canDelete}
                    onCheckedChange={(value) => row.handleToggleGpsBypass(value)}
                    aria-label={`Connexion hors zones pour ${row.name}`}
                  />
                  {row.gpsBypassHint && (
                    <span
                      className="text-xs text-muted-foreground whitespace-nowrap"
                      title={
                        row.c.gps_bypass_expires_at
                          ? `Retrait automatique à : ${new Date(row.c.gps_bypass_expires_at).toLocaleString('fr-CA')}`
                          : 'Dérogation sans date de fin'
                      }
                    >
                      {row.gpsBypassHint}
                    </span>
                  )}
                </div>
              </TableCell>
              <TableCell><StatusBadge status={row.c.status} /></TableCell>
              <TableCell className="text-right">
                <div className="flex items-center justify-end gap-1">
                  {/* « Voir » = fiche employé (`commercials/{id}`, réservé aux
                      commerciaux) : masquée pour les opérateurs. */}
                  {!row.isOperator && (
                    <Link
                      to={`/comercialDetail/${row.c.id}`}
                      className="inline-flex items-center justify-center h-8 w-8 rounded-md hover:bg-muted text-muted-foreground transition-colors"
                      title="Voir"
                      aria-label="Voir"
                    >
                      <Eye className="h-4 w-4" />
                    </Link>
                  )}
                  <RowMenu>
                    {(closeMenu) => (
                      <>
                        <button
                          onClick={() => row.handleEdit(closeMenu)}
                          className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-muted transition-colors"
                        >
                          <Pencil className="h-3.5 w-3.5" /> Modifier
                        </button>
                        {/* Envoi de la photo : la modale est déjà branchée sur la
                            page (`avatarTarget`) et l'avatar reste le raccourci
                            direct — l'entrée de menu la rend trouvable au clavier
                            et sans viser une pastille de 32 px. */}
                        <button
                          onClick={() => {
                            row.handleAvatarClick()
                            closeMenu()
                          }}
                          className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-muted transition-colors"
                        >
                          <ImagePlus className="h-3.5 w-3.5" /> Modifier la photo
                        </button>
                        <button
                          onClick={() => row.handleToggleStatus(closeMenu)}
                          className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-muted transition-colors"
                        >
                          <Power className="h-3.5 w-3.5" /> {row.toggleLabel}
                        </button>
                        {canDelete && (
                          <button
                            onClick={() => row.handleDelete(closeMenu)}
                            className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-destructive/10 text-destructive transition-colors"
                          >
                            <Trash2 className="h-3.5 w-3.5" /> Supprimer
                          </button>
                        )}
                      </>
                    )}
                  </RowMenu>
                </div>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
      {rows.length === 0 && (
        <div className="h-24 flex items-center justify-center text-muted-foreground">Aucun résultat.</div>
      )}
    </div>
  )
}
