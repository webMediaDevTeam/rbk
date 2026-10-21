import { useQuery } from '@tanstack/react-query'
import { Tags } from 'lucide-react'
import { listClientSourcesApi } from '@/api/shared.api.js'
import { useAuth } from '@/context/AuthContext.jsx'

/**
 * Onglets « Tous / Affaire / … » — **le filtre par source** (`?source=` des
 * listes de clients, origine du prospect — répertoire `sources`,
 * docs/RULES.md §13).
 *
 * Composant **partagé** par les deux écrans où la même sélection s'applique :
 * la Grande liste admin (`/clients-historique`) et le tableau de bord
 * gestionnaire, qui borne ses cartes par l'origine active. La valeur vient du
 * parent (`useSourcePreference`) : elle est persistée dans `localStorage`,
 * donc identique sur les deux pages, et survit à un rechargement.
 *
 *  - Onglets = noms du répertoire `sources` (`GET clients/sources`, triés) ;
 *  - sélection **unique** : « Tous » (paramètre `source` vide = aucun filtre)
 *    ou une seule origine — un second clic sur l'onglet actif repasse à
 *    « Tous » ; sur une liste, tout changement ramène à la 1re page (réglé par
 *    le parent) ;
 *  - **réservé ADMIN / SUPER_ADMIN** (`clients:filter-source`) : sans ce
 *    privilège la barre n'est même pas rendue. Le commercial n'accède pas à
 *    la Grande liste (`ROLES.MANAGERS` + `CheckRole:ADMIN,SUPER_ADMIN`) et sa
 *    propre liste (`/prospects`) est verrouillée **côté serveur** sur la
 *    source de son entreprise : aucun onglet de ce côté-là ;
 *  - la source active borne aussi les compteurs (badges de statut de la liste,
 *    cartes du dashboard) : la page la transmet à `GET clients/overview?source=`
 *    et `GET dashboard/stats?source=`, donc « compteur du badge = lignes
 *    rendues » (§9) tient sous chaque onglet.
 *
 * @param {string}   source  Source active (`''` = « Tous »).
 * @param {Function} onChange reçoit la nouvelle source (`''` pour « Tous »).
 */
export default function SourceTabs({ source = '', onChange }) {
  const { hasPermission } = useAuth()
  const allowed = hasPermission('clients:filter-source')

  const { data, isLoading, isError } = useQuery({
    queryKey: ['client-sources'],
    queryFn: () => listClientSourcesApi(),
    staleTime: 1000 * 60,
    retry: false,
    // Aucune requête pour un appelant sans privilège (et aucun rendu).
    enabled: allowed,
  })

  if (!allowed) return null

  const sources = Array.isArray(data?.data) ? data.data : []

  // Source active absente de la liste (onglet cliqué avant un import, ou
  // source retirée de la base) : affichée quand même, sinon la sélection
  // en cours deviendrait invisible.
  const tabs = source && !sources.includes(source) ? [...sources, source] : sources

  const pillClass = (active) => [
    'inline-flex items-center rounded-full border px-3 py-1 text-xs font-medium transition-colors',
    active
      ? 'border-transparent bg-primary text-primary-foreground'
      : 'border-border/60 bg-card text-muted-foreground hover:border-primary/40 hover:text-foreground',
  ].filter(Boolean).join(' ')

  const select = (value) => onChange?.(value === source ? '' : value)

  return (
    <div className="flex flex-wrap items-center gap-1.5" role="tablist" aria-label="Filtrer par source">
      <span className="mr-1 flex items-center gap-1.5 text-xs text-muted-foreground">
        <Tags className="h-3.5 w-3.5" aria-hidden="true" />
        Source
      </span>

      <button
        type="button"
        role="tab"
        aria-selected={!source}
        title="Tous les prospects, quelle que soit l'origine — clique pour retirer le filtre"
        onClick={() => onChange?.('')}
        className={pillClass(!source)}
      >
        Tous
      </button>

      {isLoading ? (
        Array.from({ length: 2 }).map((_, i) => (
          <div key={i} className="h-6 w-20 animate-pulse rounded-full border border-border/60 bg-card" aria-hidden="true" />
        ))
      ) : (
        tabs.map((value) => (
          <button
            key={value}
            type="button"
            role="tab"
            aria-selected={source === value}
            title={source === value
              ? `Origine « ${value} » — un second clic repasse à « Tous »`
              : `Prospects dont l'origine est « ${value} »`}
            onClick={() => select(value)}
            className={pillClass(source === value)}
          >
            {value}
          </button>
        ))
      )}

      {isError && (
        <span className="text-xs text-muted-foreground" title="La liste des origines est momentanément indisponible.">
          Origines indisponibles — filtre limité à « Tous ».
        </span>
      )}
    </div>
  )
}
