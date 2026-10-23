import { Link } from 'react-router-dom'
import { cn } from '@/lib/utils.js'

/**
 * Lien posé sur la **colonne nom** d'un tableau : le même accès que le bouton
 * « Voir » de fin de ligne, mais directement sur la donnée.
 *
 * Un vrai `<Link>` (et non un `onClick`) parce que le nom devient une URL :
 * clic du milieu / `Ctrl`+clic pour ouvrir la fiche dans un autre onglet,
 * copier le lien, retour navigateur — tout ce qu'une ligne rendue cliquable ne
 * sait pas faire. `stopPropagation` garde l'exclusivité quand la ligne entière
 * navigue aussi, pour éviter deux navigations concurrentes.
 *
 * Le soulignement est **permanent** et discret (`decoration-border`) : dans une
 * liste de 50 lignes, un lien qui ne se signale qu'au survol de la souris reste
 * invisible — la ligne doit dire « cliquable » avant le curseur. Il prend la
 * couleur d'action au survol, comme le bouton « Voir ».
 *
 * @param {string} to      destination (route interne, même cible que « Voir »).
 * @param {string} title   bulle explicative — « voir le détail de … ».
 * @param {string} [className]  ajout local (`truncate`, `font-medium`…).
 */
export default function RowLink({ to, title, className, children }) {
  return (
    <Link
      to={to}
      title={title}
      onClick={(event) => event.stopPropagation()}
      className={cn(
        'underline decoration-border underline-offset-2 transition-colors hover:text-primary hover:decoration-primary',
        className,
      )}
    >
      {children}
    </Link>
  )
}
