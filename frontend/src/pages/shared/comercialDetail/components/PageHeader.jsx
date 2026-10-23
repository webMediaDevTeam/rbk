import { Link } from 'react-router-dom'
import { Eye, SquarePen } from 'lucide-react'
import Button from '@/components/ui/button.jsx'

/**
 * En-tête de la fiche employé : titre + actions.
 *
 * « Modifier » (variante **avertissement**, ambre) est posé sur le même principe
 * que la fiche client : la couleur signale une action qui **écrit** sur la
 * donnée, par opposition au retour (lecture seule). Il ouvre la modale
 * d'édition complète de l'employé (`CommercialUpdateModal`) — la fiche elle-même
 * reste en lecture, les privilèges et la dérogation GPS ne se règlent donc qu'au
 * travers d'un formulaire avec ses contrôles.
 *
 * Le bouton n'est rendu que si `onEdit` est fourni : la page le passe dès que la
 * réponse a livré l'identité de l'employé (un `onEdit` sans donnée à éditer
 * ouvrirait une modale vide).
 */
export default function PageHeader({ name, onEdit }) {
  return (
    <div className="flex items-center justify-between gap-3">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-foreground">{name}</h1>
        <p className="text-sm text-muted-foreground mt-1">Informations de l'employé, ses statistiques et son entreprise.</p>
      </div>
      <div className="flex items-center gap-2">
        {onEdit && (
          <Button
            variant="warning"
            size="sm"
            onClick={onEdit}
            className="shrink-0"
          >
            <SquarePen className="mr-1.5 h-4 w-4" />
            Modifier
          </Button>
        )}
        <Link
          to="/commerciaux"
          className="inline-flex items-center gap-2 px-2.5 lg:px-4 h-9 rounded-lg text-sm font-medium bg-muted text-foreground hover:bg-muted/80 transition-colors"
        >
          <Eye className="h-4 w-4" /> Retour
        </Link>
      </div>
    </div>
  )
}
