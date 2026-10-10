<?php

namespace App\Http\Controllers\Api\V1\Shared\Concerns;

use App\Models\Enterprise;
use App\Services\RingCentralService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Identifiants RingCentral lus **en base** (`enterprises.ringcentral_*`)
 * plutôt que dans le `.env`.
 *
 * Sources (migrations) :
 *   - `2026_10_08_120000_add_ringcentral_credentials_to_enterprises_table`
 *     → `ringcentral_client_id` / `ringcentral_client_secret` / `ringcentral_token` ;
 *   - `2026_10_09_000001_add_ringcentral_auth_to_enterprises_table`
 *     → `ringcentral_auth` (jeton OAuth déjà obtenu, réutilisé sans nouvel
 *       échange JWT, réécrit à chaque renouvellement).
 *
 * Une entreprise = son propre compte : le jeton du `.env` n'est **jamais**
 * utilisé dès qu'une entreprise est résoluble (voir
 * `Enterprise::getRingCentralCredentials()`).
 */
trait ConfiguresRingCentralEnterprise
{
    /** L'entreprise existe mais n'a pas ses trois identifiants RC. */
    protected const RINGCENTRAL_CREDENTIALS_MISSING = 'Cette entreprise doit avoir son client ID, son secret et son JWT RingCentral enregistrés.';

    /**
     * Entreprise dont le compte RingCentral doit servir à la requête :
     *
     *   1. `?enterprise_id=` (query) ou `enterprise_id` (body) — appel
     *      explicite (modales employé, page détail entreprise) ;
     *   2. sinon, l'entreprise de l'utilisateur connecté
     *      (`users → employees.enterprise_id`).
     *
     * @return ?Enterprise `null` = aucune entreprise ⇒ le service garde sa
     *                     configuration courante (legacy `.env`)
     *
     * @throws ValidationException identifiant explicite inconnu, ou compte
     *                             d'entreprise incomplet (422)
     */
    protected function configureRingCentral(Request $request, RingCentralService $ringCentral): ?Enterprise
    {
        $explicit = $request->query('enterprise_id') ?: $request->input('enterprise_id');

        if (filled($explicit)) {
            $enterprise = Enterprise::query()->find($explicit);

            if (! $enterprise instanceof Enterprise) {
                throw ValidationException::withMessages([
                    'enterprise_id' => 'Entreprise introuvable.',
                ]);
            }
        } else {
            $enterpriseId = $request->user()?->employee?->enterprise_id;

            if (! filled($enterpriseId)) {
                return null;
            }

            $enterprise = Enterprise::query()->find($enterpriseId);

            if (! $enterprise instanceof Enterprise) {
                return null;
            }
        }

        // Compte incomplet : on **refuse** le repli silencieux sur le
        // `.env` — mieux vaut un 422 explicite qu'un autre compte appelé.
        if (! $enterprise->hasOwnRingCentralAccount()) {
            throw ValidationException::withMessages([
                'enterprise_id' => self::RINGCENTRAL_CREDENTIALS_MISSING,
            ]);
        }

        $ringCentral->configure($enterprise->getRingCentralCredentials());

        return $enterprise;
    }
}
