<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Privilège de libération » — `users.has_permission` (drapeau posé par
 * l'admin dans la modale Commercial créer / éditer) :
 *
 *  - **ADMIN / SUPER_ADMIN** : toujours autorisés (la hiérarchie passe
 *    au-dessus du drapeau) ;
 *  - **COMERCIAL** : autorisé **uniquement** si `has_permission = true`,
 *    sinon **403**.
 *
 * Appliqué à `POST clients/{id}/blacklist` (mise en liste noire depuis la
 * fiche client) et `POST reservations/release-pending` (« Libérer la liste »)
 * — voir `routes/api/commercial.php` et docs/RULES.md §7.1. Le middleware
 * `CheckRole:COMERCIAL` (groupe des routes) tourne avant : seul un
 * connecté du bon rôle peut atteindre cette vérification.
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        // Hiérarchie de gestion : le privilège ne s'applique qu'aux
        // commerciaux (les admins ont tous les droits).
        if (in_array($user->role, ['ADMIN', 'SUPER_ADMIN'], true)) {
            return $next($request);
        }

        if (! $user->has_permission) {
            return response()->json([
                'message' => 'Accès refusé : privilège de libération requis.',
            ], 403);
        }

        return $next($request);
    }
}
