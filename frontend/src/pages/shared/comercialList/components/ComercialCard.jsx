import { Link } from 'react-router-dom'
import { AlertTriangle, Eye, ImagePlus, Pencil, Power, Trash2 } from 'lucide-react'
import Badge from '@/components/ui/badge'
import Switch from '@/components/ui/switch.jsx'
import StatusBadge from './StatusBadge'
import UserAvatar from '@/pages/shared/components/UserAvatar/index.jsx'
import RowMenu from '@/pages/shared/components/RowMenu/index.jsx'
import RowLink from '@/pages/shared/components/RowLink/index.jsx'
import ComercialStatsChips from './ComercialStatsChips.jsx'
import { useComercialCard } from './useComercialCard.js'

export default function ComercialCard(props) {
  const {
    comercial,
    name,
    enterpriseName,
    rcNumber,
    rcDuplicate,
    gpsBypass,
    gpsBypassHint: bypassHint,
    hasPermission,
    isOperator,
    stats,
    toggleLabel,
    canDelete,
    handleAvatarClick,
    handleEdit,
    handleToggleStatus,
    handleToggleGpsBypass,
    handleTogglePrivilege,
    handleDelete,
  } = useComercialCard(props)

  return (
    <div className="relative flex flex-col rounded-xl border border-border bg-card text-card-foreground p-5 shadow-sm transition-all hover:shadow-md">
      <div className="absolute top-3 right-3">
        <RowMenu>
          {(closeMenu) => (
            <>
              <button
                onClick={() => handleEdit(closeMenu)}
                className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-muted transition-colors"
              >
                <Pencil className="h-3.5 w-3.5" /> Modifier
              </button>
              {/* Envoi de la photo : la modale est déjà branchée sur la page
                  (`avatarTarget`) et l'avatar reste le raccourci direct. */}
              <button
                onClick={() => {
                  handleAvatarClick()
                  closeMenu()
                }}
                className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-muted transition-colors"
              >
                <ImagePlus className="h-3.5 w-3.5" /> Modifier la photo
              </button>
              <button
                onClick={() => handleToggleStatus(closeMenu)}
                className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-muted transition-colors"
              >
                <Power className="h-3.5 w-3.5" /> {toggleLabel}
              </button>
              {canDelete && (
                <button
                  onClick={() => handleDelete(closeMenu)}
                  className="flex items-center gap-2 w-full px-3 py-2 text-sm rounded-lg hover:bg-destructive/10 text-destructive transition-colors"
                >
                  <Trash2 className="h-3.5 w-3.5" /> Supprimer
                </button>
              )}
            </>
          )}
        </RowMenu>
      </div>

      <div className="flex items-center gap-3 mb-4">
        <UserAvatar user={comercial} size="md" onEdit={handleAvatarClick} />
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold truncate flex items-center gap-1.5">
            {/* Nom = fiche employé, comme dans le tableau : l'opérateur n'a pas de
                fiche et reste sur texte seul. */}
            {isOperator ? name : (
              <RowLink
                to={`/comercialDetail/${comercial.id}`}
                title={`Voir la fiche de ${name}`}
                className="truncate"
              >
                {name}
              </RowLink>
            )}
            {/* Rôle — « Opérateur » badgeé pour le distinguer d'un commercial. */}
            {isOperator && (
              <Badge variant="info" className="uppercase">Opérateur</Badge>
            )}
          </p>
          <p className="text-xs text-muted-foreground truncate">{comercial.email}</p>
        </div>
      </div>

      <div className="space-y-2 text-sm flex-1">
        <div>
          <span className="text-muted-foreground text-xs">N° RingCentral</span>
          <p className="flex items-center gap-1.5 truncate">
            {rcNumber ? (
              <>
                <span className={`tabular-nums ${rcDuplicate ? 'font-semibold text-red-600' : ''}`}>{rcNumber}</span>
                {rcDuplicate && (
                  <span
                    className="inline-flex animate-bounce items-center gap-1 rounded-md bg-red-500/15 px-1.5 py-0.5 text-xs font-semibold text-red-600 ring-1 ring-red-500/40"
                    title="Ce numéro RingCentral est partagé avec un autre employé — corrigez la source d'appel"
                  >
                    <AlertTriangle className="h-3.5 w-3.5" /> Doublon
                  </span>
                )}
              </>
            ) : (
              '—'
            )}
          </p>
        </div>
        <div>
          <span className="text-muted-foreground text-xs">Entreprise</span>
          <p className="truncate">{enterpriseName}</p>
        </div>
        <div>
          <span className="text-muted-foreground text-xs">Activité</span>
          <div className="mt-1">
            <ComercialStatsChips stats={stats} userId={comercial.id} linked={!isOperator} />
          </div>
        </div>
        <div className="flex items-center justify-between">
          {/* Privilège commercial : même switch inline que « Connexion hors
              zones » ; réservé au COMERCIAL (§7.1 — ne protège que la
              libération de la liste). */}
          <span className="text-muted-foreground text-xs" title="Privilège de libération (§7.1) — autorise ce commercial à libérer ses réservations en attente">Privilège de libération</span>
          {comercial.role === 'COMERCIAL' ? (
            <Switch
              checked={hasPermission}
              disabled={!canDelete}
              onCheckedChange={(value) => handleTogglePrivilege(value)}
              aria-label={`Privilège de libération pour ${name}`}
            />
          ) : (
            <span className="text-muted-foreground text-xs">—</span>
          )}
        </div>
        <div className="flex items-center justify-between">
          {/* Dérogation GPS : accord **limité à 24 h**, le libellé indique le
              temps restant avant le retrait automatique. */}
          <div className="flex flex-col">
            <span className="text-muted-foreground text-xs" title="Autoriser la connexion en dehors des zones GPS — 24 h après activation, puis retrait automatique">Connexion hors zones (24 h)</span>
            {bypassHint && (
              <span
                className="text-xs text-muted-foreground"
                title={
                  comercial.gps_bypass_expires_at
                    ? `Retrait automatique à : ${new Date(comercial.gps_bypass_expires_at).toLocaleString('fr-CA')}`
                    : 'Dérogation sans date de fin'
                }
              >
                {bypassHint}
              </span>
            )}
          </div>
          <Switch
            checked={gpsBypass}
            disabled={!canDelete}
            onCheckedChange={(value) => handleToggleGpsBypass(value)}
            aria-label={`Connexion hors zones pour ${name}`}
          />
        </div>
      </div>

      <div className="flex items-center justify-between mt-4 pt-3 border-t border-border">
        <StatusBadge status={comercial.status} />
        {/* « Voir » = fiche employé (réservée aux commerciaux) : masquée
            pour les opérateurs. */}
        {!isOperator && (
          <Link
            to={`/comercialDetail/${comercial.id}`}
            className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground transition-colors"
          >
            <Eye className="h-4 w-4" /> Voir
          </Link>
        )}
      </div>
    </div>
  )
}
