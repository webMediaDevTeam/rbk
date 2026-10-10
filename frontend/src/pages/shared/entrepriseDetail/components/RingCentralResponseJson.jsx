import { Braces } from 'lucide-react'

/**
 * Réponse **brute** de l'appel RingCentral de la fiche entreprise
 * (`GET entreprises/{id}/ringcentral-status`) rendue en JSON — aidant au
 * débogage (les badges du bandeau ne montrent que `active` / `reason`).
 *
 * - pliable / dépliable (`<details>`) : replié par défaut, il n'encombre pas
 *   la fiche ;
 * - en cas d'échec de la vérification, le corps d'erreur serveur
 *   (`error.response.data`) est affiché à la place du succès ;
 * - la page est réservée ADMIN / SUPER_ADMIN : l'affichage brut n'est pas
 *   exposé à un rôle non autorisé.
 *
 * @param {Object|null} response     Corps `{success, data}` de la réponse.
 * @param {Object|null} errorResponse Corps d'erreur (`error.response.data`).
 */
export default function RingCentralResponseJson({ response = null, errorResponse = null }) {
  const payload = errorResponse ?? response

  if (!payload) return null

  return (
    <details className="mt-5 border-t border-border pt-4">
      <summary className="flex cursor-pointer items-center gap-1.5 text-xs font-semibold text-muted-foreground hover:text-foreground">
        <Braces className="h-3.5 w-3.5" aria-hidden="true" />
        Réponse API RingCentral (JSON)
      </summary>
      <pre className="mt-2 max-h-72 overflow-auto whitespace-pre-wrap break-all rounded-md border border-border bg-background p-3 text-xs text-foreground">
        {JSON.stringify(payload, null, 2)}
      </pre>
    </details>
  )
}
