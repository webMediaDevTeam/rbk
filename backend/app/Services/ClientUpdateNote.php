<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Note;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Note « Mise à jour » (type `MISE_A_JOUR`) : journalise toute modification
 * **humaine** d'une fiche client — `PUT /commercials/clients/{id}` (modale
 * « Modifier le client », admins + opérateurs), `PATCH /commercials/
 * clients/{id}/phone` (saisie du numéro) et `PATCH /clients/{id}` (édition
 * par le commercial sur sa réservation).
 *
 * L'émetteur de la note est l'utilisateur qui a mis à jour la fiche ; la
 * description égrène, champ par champ et **en français**, l'ancienne et la
 * nouvelle valeur : `Fiche mise à jour — Nom : « α » → « β » · Téléphone :
 * « (vide) » → « 514-353-5820 ».`
 *
 * `getChanges()` ne retient que les attributs **réellement écrits** par le
 * dernier `save()` (rien n'est journalisé si la requête ne change rien),
 * mais le modèle ayant déjà synchronisé ses valeurs d'origine à ce moment,
 * l'appelant passe le cliché `getOriginal()` pris **avant** la sauvegarde.
 * Seuls les champs métier connus (`FIELD_LABELS`) sont comparés : les
 * colonnes dérivées posées par les mutateurs (`phone_normalized`,
 * `simple_name`…) sont des miroirs, pas des données saisies.
 */
class ClientUpdateNote
{

    /** Libellés français des champs éditables (repli : `_` → espace). */
    private const FIELD_LABELS = [
        'name' => 'Nom',
        'enterprise_name' => 'Entreprise',
        'email' => 'Courriel',
        'phone' => 'Téléphone',
        'municipality' => 'Municipalité',
        'administrative_region' => 'Région administrative',
        'full_address' => 'Adresse complète',
        'licence_number' => 'Numéro de licence',
        'licence_propre' => 'Licence en propre',
        'licence_propre_numero' => 'Numéro licence en propre',
        'licence_status' => 'Statut de licence',
        'licence_start_date' => 'Début de licence',
        'licence_end_date' => 'Fin de licence',
        'neq' => 'NEQ',
        'representative_name' => 'Représentant',
        'intervenant_name' => 'Intervenant',
        'source' => 'Source',
        'note_personnalise' => 'Note personnalisée',
        'respondent_count' => 'Nombre de répondants',
        'respondents' => 'Répondants',
        'sub_category_count' => 'Nombre de sous-catégories',
        'categories' => 'Catégories',
        'surety_company' => 'Compagnie de cautionnement',
        'cautionnement_compagnie' => 'Cautionnement (compagnie)',
        'surety_amount' => 'Montant du cautionnement',
    ];

    /** Champs booléens : affichés « Oui / Non » plutôt que 1 / 0. */
    private const BOOL_FIELDS = ['licence_propre'];

    /**
     * @param  array<string, mixed>  $before  cliché `getOriginal()` pris avant la sauvegarde
     */
    public static function log(Client $client, ?User $user, array $before): ?Note
    {
        $parts = [];
        foreach ($client->getChanges() as $field => $new) {
            // Whitelist : les champs métier connus uniquement (colonnes
            // dérivées et `is_manually_updated` restent silencieux).
            if (! isset(self::FIELD_LABELS[$field])) {
                continue;
            }

            $oldText = self::value($before[$field] ?? null, $field);
            $newText = self::value($new, $field);
            if ($oldText === $newText) {
                continue;
            }

            $parts[] = sprintf(
                '%s : « %s » → « %s »',
                self::FIELD_LABELS[$field],
                $oldText,
                $newText,
            );
        }

        if ($parts === []) {
            return null;
        }

        return Note::create([
            'client_id' => $client->id,
            'sender_id' => $user?->id ?? Note::SENDER_SYSTEM,
            'type' => Note::TYPE_MISE_A_JOUR,
            'description' => Str::limit('Fiche mise à jour — '.implode(' · ', $parts).'.', 1500, ' …'),
        ]);
    }

    /** Valeur lisible en français : `(vide)`, Oui/Non, listes, troncature. */
    private static function value(mixed $value, string $field): string
    {
        if (in_array($field, self::BOOL_FIELDS, true)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Oui' : 'Non';
        }

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return '(vide)';
        }

        if (is_array($value)) {
            $value = implode(
                ', ',
                array_map(fn ($v): string => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE), $value)
            );
        }

        return Str::limit(trim((string) $value), 80, ' …');
    }
}
