<?php

namespace App\Services\Client;

use App\Models\Client;
use App\Models\Note;
use App\Models\Rappel;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Import prospects — API **publique** n8n / scraper (docs/RULES.md §12).
 *
 * Extrait de `App\Models\Client` : tous les traitements par lot
 * (upsert, suppression, liste noire, indisponibilité, fausses
 * réservations NON) avec leurs `DB::transaction` et la clé métier
 * **licence**. Le modèle ne garde plus que relations, scopes simples
 * et casts.
 *
 * API publique **statique** : `ClientImportService::bulkUnavailableFromPhone(...)`
 * — le contrôleur `PublicClientController` l'appelle directement, sans
 * passer par le modèle.
 */
class ClientImportService
{
    /**
     * Payload n8n (clés d'affichage en français) → colonnes `clients` en
     * snake_case. Seule porte d'entrée des données de licence : aucune clé
     * française n'est écrite telle quelle dans la base.
     *
     * Exclues volontairement — état applicatif que le payload ne doit jamais
     * repousser : `status`, `is_blacklisted`, `returned_at` et la réservation
     * du commercial (cf. docs/RULES.md §12).
     */
    public const PAYLOAD_MAP = [
        'Licence' => 'licence_number',
        'Licence (propre)' => 'licence_propre_numero',
        "Nom de l'intervenant / Entreprise" => 'enterprise_name',
        'Statut de la licence' => 'licence_status',
        'NEQ' => 'neq',
        'Adresse complète' => 'full_address',
        'Municipalité' => 'municipality',
        'Région administrative' => 'administrative_region',
        'Téléphone' => 'phone',
        'Courriel' => 'email',
        'Nombre de répondants' => 'respondent_count',
        'Répondants / Interlocuteurs (Qualifications)' => 'respondents',
        'Nombre de sous-catégories' => 'sub_category_count',
        'Catégories et sous-catégories autorisées' => 'authorized_categories',
        'Cautionnement (Compagnie / Association)' => 'surety_company',
        'Montant de la caution ($)' => 'surety_amount',
        'Date de début / délivrance' => 'licence_start_date',
        'Date de fin / paiement annuel' => 'licence_end_date',
        // Origine du prospect (répertoire `sources`) — défaut `Affaire`
        // (`Client::DEFAULT_SOURCE`) quand le payload n'envoie rien ; une
        // valeur vide n'efface jamais celle déjà en place.
        'Source' => 'source',
    ];

    /**
     * Normalise un payload n8n en attributs `clients` prêts pour
     * create()/update() : clés ramenées aux colonnes snake_case (sans tenir
     * compte de la casse ni de la ponctuation — `l'intervenant` et
     * `l’intervenant` donnent la même colonne), chaînes vides → NULL et types
     * alignés sur les casts du modèle.
     *
     * @return array<string, mixed>
     */
    public static function attributesFromPayload(array $payload): array
    {
        $columns = [
            'cautionnementcompagnie' => 'cautionnement_compagnie',
        ];
        foreach (self::PAYLOAD_MAP as $payloadKey => $column) {
            $columns[self::normalizePayloadKey($payloadKey)] = $column;
            $columns[self::normalizePayloadKey($column)] = $column;
        }

        $attributes = [];
        foreach ($payload as $key => $value) {
            $column = $columns[self::normalizePayloadKey((string) $key)] ?? null;

            if ($column === null) {
                continue; // clé inconnue ou état applicatif : ignorée
            }

            $attributes[$column] = self::castPayloadValue($column, $value);
        }

        // Nettoyage applicatif : le webhook ne valide pas (choix projet §12),
        // il normalise — voir `scrubAttributes()`.
        $attributes = self::scrubAttributes($attributes);

        // Le payload ne porte qu'un seul nom (« Nom de l'intervenant /
        // Entreprise ») : `enterprise_name` fait foi, `name` (affichage) et
        // `intervenant_name` en héritent pour ne pas rester vides côté UI.
        if (isset($attributes['enterprise_name'])) {
            $attributes['name'] ??= $attributes['enterprise_name'];
            $attributes['intervenant_name'] ??= $attributes['enterprise_name'];
        }

        return $attributes;
    }

    /**
     * Import d'un enregistrement scraper / webhook : **crée ou met à jour** le
     * client identifié par sa licence (docs/RULES.md §12).
     *
     *  - **clé d'upsert** : `licence_number` ; en repli
     *    `licence_propre_numero` (index `UNIQUE`) quand le payload ne porte
     *    pas de numéro de licence classique ;
     *  - **jamais d'état applicatif** : `status`, `is_blacklisted`,
     *    `returned_at` et la réservation sont exclus de `PAYLOAD_MAP` — à la
     *    création, valeurs neuves `AVAILABLE` / non blacklisté /
     *    `returned_at = NULL` ;
     *  - une ligne **identique** n'est pas réécrite (`unchanged`) :
     *    `updated_at` ne bouge donc pas d'une resynchronisation à l'autre ;
     *  - dérivations identiques à `ClientsFromJsonSeeder` : `categories` ←
     *    `authorized_categories`, `licence_propre` ← numéro propre renseigné.
     *
     * @return string `created` | `updated` | `unchanged` | `skipped_manual`
     *
     * @throws InvalidArgumentException si aucune clé d'upsert n'est fournie
     */
    public static function upsertFromScraperPayload(array $payload): string
    {
        $attributes = self::attributesFromPayload($payload);

        $licenceNumber = $attributes['licence_number'] ?? null;
        $licencePropre = $attributes['licence_propre_numero'] ?? null;

        if (($licenceNumber === null || $licenceNumber === '') && $licencePropre === null) {
            throw new InvalidArgumentException(
                'Clé d\'upsert manquante : « Licence » (ou « Licence (propre) » en repli).'
            );
        }

        // Dérivations (mêmes conventions que l'import JSON, §12).
        if (array_key_exists('authorized_categories', $attributes)) {
            $attributes['categories'] = $attributes['authorized_categories'] ?? [];
        }

        if (array_key_exists('licence_propre_numero', $attributes)) {
            $attributes['licence_propre'] = $licencePropre !== null;
        }

        $client = Client::query()
            ->where(
                ($licenceNumber === null || $licenceNumber === '')
                    ? ['licence_propre_numero' => $licencePropre]
                    : ['licence_number' => $licenceNumber]
            )
            ->first();

        if ($client === null) {
            Client::create(array_merge(
                // Défauts de création : la source « Affaire » n'est qu'un
                // repli — le payload peut la remplacer avec « Source ».
                ['categories' => [], 'source' => Client::DEFAULT_SOURCE],
                $attributes,
                // État applicatif : toujours neuf à la création, quel que
                // soit ce que le payload aurait pu tenter d'envoyer.
                ['status' => Client::STATUS_AVAILABLE, 'is_blacklisted' => false, 'returned_at' => null]
            ));

            return 'created';
        }

        // Fiche déjà saisie à la main (`is_manually_updated = true`, posé
        // par PATCH commercials/clients/{id}/phone) : l'import scraper / n8n
        // ne réécrit **aucun** champ de la ligne. Retour `skipped_manual`,
        // compté à part dans le lot (docs/public_api.md).
        if ($client->is_manually_updated) {
            return 'skipped_manual';
        }

        // Numéro déjà en place (saisi à la main depuis l'accès Admin) : un
        // payload sans « Téléphone » — ou vide — ne doit **pas** l'effacer.
        // L'enrichissement ne fait que compléter le numéro manquant ; un
        // client renseigné à la main ne repasse donc pas « Sans téléphone »
        // au prochain import.
        if (array_key_exists('phone', $attributes)
            && $attributes['phone'] === null
            && trim((string) ($client->phone ?? '')) !== '') {
            unset($attributes['phone']);
        }

        $client->fill($attributes);

        if (! $client->isDirty()) {
            return 'unchanged';
        }

        $client->save();

        return 'updated';
    }

    /**
     * Import **en masse** du webhook public `POST clients/bulk-upsert`
     * (docs/RULES.md §12).
     *
     * Chaque enregistrement est traité **dans sa propre transaction** : une
     * ligne invalide (ou un conflit d'unicité) est comptée dans `failed` et
     * détaillée dans `errors`, sans annuler le reste du lot — l'import partiel
     * reste exploitable côté scraper.
     *
     * @param  list<mixed>  $payloads
     * @return array{received:int, processed:int, created:int, updated:int, unchanged:int, skipped_manual:int, failed:int, errors:list<array{index:int, licence_number:?string, error:string}>}
     */
    public static function bulkUpsertFromScraperPayload(array $payloads): array
    {
        $result = [
            'received' => count($payloads),
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped_manual' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach (array_values($payloads) as $index => $payload) {
            try {
                if (! is_array($payload)) {
                    throw new InvalidArgumentException('Enregistrement attendu : un objet JSON par ligne.');
                }

                $outcome = DB::transaction(fn () => self::upsertFromScraperPayload($payload));

                $result[$outcome] += 1;
                $result['processed'] += 1;
            } catch (QueryException $e) {
                // Violation d'unicité (ex. `licence_propre_numero` déjà pris
                // par un autre client) : le SQL n'est jamais exposé au
                // client du webhook, il est journalisé côté serveur.
                Log::warning('Import public de clients : conflit de données.', [
                    'index' => $index,
                    'error' => $e->getMessage(),
                ]);

                $result['failed'] += 1;
                $result['errors'][] = [
                    'index' => $index,
                    'licence_number' => self::payloadLicenceNumber($payload),
                    'error' => 'Conflit de données : numéro de licence déjà utilisé par un autre client.',
                ];
            } catch (Throwable $e) {
                $result['failed'] += 1;
                $result['errors'][] = [
                    'index' => $index,
                    'licence_number' => self::payloadLicenceNumber($payload),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /** Clé d'upsert d'un item brut (rapport d'erreurs du webhook). */
    private static function payloadLicenceNumber(mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        foreach (['licence_number', 'Licence', ''] as $key) {
            $value = $payload[$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * Suppression **en masse** du webhook public `POST|DELETE
     * clients/bulk-delete` (docs/RULES.md §12).
     *
     * Garantie centrale : **un client qui porte des données liées n'est pas
     * supprimé.** Les tables enfants (`reservations`, `notes`, `rappels`)
     * sont toutes en `cascadeOnDelete` — supprimer le client effacerait son
     * historique. L'entrée est alors comptée dans `skipped`, détaillée dans
     * `skipped_items` avec le décompte des liens, et **la boucle passe au
     * client suivant** : un lot ne supprime que les clients vierges.
     *
     * Formes d'item acceptées : une chaîne (numéro de licence) **ou** un
     * objet payload (clés françaises, repli `licence_propre_numero`) —
     * mêmes formes que l'import.
     *
     * @param  list<mixed>  $items
     * @return array{received:int, processed:int, deleted:int, skipped:int, missing:int, failed:int, skipped_items:list<array{index:int, licence_number:?string, linked:array{reservations:int, notes:int, rappels:int}}>, missing_items:list<array{index:int, licence_number:?string}>, errors:list<array{index:int, licence_number:?string, error:string}>}
     */
    public static function bulkDeleteFromScraper(array $items): array
    {
        $result = [
            'received' => count($items),
            'processed' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'missing' => 0,
            'failed' => 0,
            'skipped_items' => [],
            'missing_items' => [],
            'errors' => [],
        ];

        foreach (array_values($items) as $index => $item) {
            try {
                $key = self::scraperLookupKey($item);

                if ($key === []) {
                    throw new InvalidArgumentException('Clé manquante : « Licence » (ou « Licence (propre) »).');
                }

                $licence = isset($key['licence_number'])
                    ? (string) $key['licence_number']
                    : (string) $key['licence_propre_numero'];

                // Transaction par ligne : la vérification des liens et la
                // suppression forment un seul geste (aucun client supprimé
                // pendant qu'une réservation viendrait d'être créée).
                $step = DB::transaction(function () use ($key, $licence): array {
                    $client = Client::query()
                        ->where($key)
                        ->lockForUpdate()
                        ->withCount(['reservations', 'notes', 'rappels'])
                        ->first();

                    if ($client === null) {
                        return ['outcome' => 'missing'];
                    }

                    $linked = [
                        'reservations' => (int) $client->reservations_count,
                        'notes' => (int) $client->notes_count,
                        'rappels' => (int) $client->rappels_count,
                    ];

                    if (array_sum($linked) > 0) {
                        // Données liées : on **ignore** cette suppression et
                        // on passe au client suivant.
                        return [
                            'outcome' => 'skipped',
                            'licence_number' => $client->licence_number !== null && $client->licence_number !== ''
                                ? $client->licence_number
                                : $licence,
                            'linked' => $linked,
                        ];
                    }

                    // Suppression Eloquent (pas la requête brute) : le
                    // événement `deleted` invalide les listes distinctes.
                    $client->delete();

                    return ['outcome' => 'deleted'];
                });

                $result['processed'] += 1;

                if ($step['outcome'] === 'deleted') {
                    $result['deleted'] += 1;
                } elseif ($step['outcome'] === 'skipped') {
                    $result['skipped'] += 1;
                    $result['skipped_items'][] = [
                        'index' => $index,
                        'licence_number' => $step['licence_number'],
                        'linked' => $step['linked'],
                    ];
                } else {
                    $result['missing'] += 1;
                    $result['missing_items'][] = ['index' => $index, 'licence_number' => $licence];
                }
            } catch (QueryException $e) {
                Log::warning('Suppression publique de clients : conflit de données.', [
                    'index' => $index,
                    'error' => $e->getMessage(),
                ]);

                $result['failed'] += 1;
                $result['errors'][] = [
                    'index' => $index,
                    'licence_number' => self::payloadLicenceNumber($item),
                    'error' => 'Conflit de données : suppression impossible.',
                ];
            } catch (Throwable $e) {
                $result['failed'] += 1;
                $result['errors'][] = [
                    'index' => $index,
                    'licence_number' => self::payloadLicenceNumber($item),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * Clés d'item acceptées pour la conversion en liste noire par nom —
     * forme normalisée (`self::normalizePayloadKey()`), donc insensible à
     * la casse et à la ponctuation : `name`, `enterprise_name`,
     * `intervenant_name` et leurs équivalents français.
     */
    private const BLACKLIST_NAME_KEYS = [
        'name',
        'nom',
        'enterprisename',
        'entreprise',
        'intervenantname',
        'nomdelintervenantentreprise',
    ];

    /**
     * Clés d'item acceptées pour une **licence** — forme normalisée
     * (`self::normalizePayloadKey()`) : `licence`, `licence_number`,
     * `licence_propre` (« Licence (propre) »), `licence_propre_numero`.
     */
    private const BLACKLIST_LICENCE_KEYS = [
        'licence',
        'licences',
        'licencenumber',
        'numerolicence',
        'licencepropre',
        'licenceproprenumero',
    ];

    /** Cible = **nom** d'entreprise / d'intervenant (mode historique). */
    public const BLACKLIST_MODE_NAME = 'name';

    /** Cible = **licence** (« Licence (propre) », sinon « Licence »). */
    public const BLACKLIST_MODE_LICENCE = 'licence';

    /**
     * Cible déduite **item par item** : licence si l'item porte une clé de
     * licence, sinon nom (enveloppe `{"clients": [...]}` ou liste JSON nue
     * du scraper).
     */
    public const BLACKLIST_MODE_AUTO = 'auto';

    /**
     * Conversion **en masse** en liste noire, cible = **nom**
     * — endpoint public **temporaire** `POST clients/convert-to-blacklist`
     * (spec : `docs/convert_to_blacklist_api.md`).
     *
     * @param  list<mixed>  $items
     * @return array<string, mixed>
     */
    public static function convertToBlacklistFromName(array $items): array
    {
        return self::convertToBlacklistPayload($items, self::BLACKLIST_MODE_NAME);
    }

    /**
     * Conversion **en masse** en liste noire, cible = **licence**
     * (endpoint public, spec `docs/convert_to_blacklist_api.md` §3.2).
     *
     * Recherche **exacte** dans l'ordre : `licence_propre_numero` (numéro RBQ
     * de « Licence (propre) », colonne `integer`) puis `licence_number`
     * (colonne texte « Licence »). Un même numéro peut viser plusieurs
     * lignes : **toutes** sont passées en liste noire.
     *
     * @param  list<mixed>  $items  chaîne nue (`"1234"`) ou objet
     *                              (`{"licence": "…"}`, `{"Licence (propre)": …}`)
     * @return array<string, mixed>
     */
    public static function convertToBlacklistFromLicence(array $items): array
    {
        return self::convertToBlacklistPayload($items, self::BLACKLIST_MODE_LICENCE);
    }

    /**
     * Conversion **en masse** en liste noire, cible **déduite de chaque item**
     * (licence si l'item en porte une, sinon nom).
     *
     * @param  list<mixed>  $items
     * @return array<string, mixed>
     */
    public static function convertToBlacklistFromPayload(array $items): array
    {
        return self::convertToBlacklistPayload($items, self::BLACKLIST_MODE_AUTO);
    }

    /**
     * Boucle commune aux trois modes : même rapport consolidé, seule la
     * cible (nom / licence) change.
     *
     * Recherche par nom **partielle et insensible à la casse des deux côtés** :
     * `LOWER(enterprise_name) LIKE '%nom%' OR LOWER(name) LIKE '%nom%'` —
     * le nom est une **sous-chaîne**, donc une saisie partielle atteint
     * plusieurs entreprises. La licence est, elle, une égalité **exacte**
     * (`clientsMatchingLicence()`). Un nom ou un numéro peut viser plusieurs
     * lignes : **toutes** passent en liste noire.
     *
     * **Rapport consolidé** (docs/convert_to_blacklist_api.md §5) :
     *
     *   processed  items traités **sans erreur** (= succès)
     *   zapped     lignes clients réellement mises en liste noire
     *   ignored    items **sans effet** : introuvable OU déjà blacklisté
     *   failed     items en échec → `errors[]`
     *
     * Une cible **inexistante n'est pas une erreur** : elle compte dans
     * `not_found`, donc dans `ignored`, et le lot continue. Chaque item est
     * traité **dans sa propre transaction** : un item invalide est compté
     * dans `failed` sans annuler le reste du lot — même contrat que
     * `bulkUpsertFromScraperPayload()`. Ré-exécutable : un second passage
     * rend `zapped = 0` et `ignored = received`.
     *
     * Chaque ligne de rapport porte `type` (`name` / `licence`), `key` (la
     * cible telle que reçue) et, selon le mode, `name` **ou** `licence`
     * (`null` pour le mode non concerné — le contrat historique est préservé).
     *
     * @param  list<mixed>  $items
     * @return array{received:int, processed:int, matched:int, zapped:int, ignored:int, not_found:int, already_blacklisted:int, failed:int, zapped_items:list<array<string, mixed>>, ignored_items:list<array<string, mixed>>, errors:list<array<string, mixed>>}
     */
    private static function convertToBlacklistPayload(array $items, string $mode): array
    {
        $result = [
            'received' => count($items),
            'processed' => 0,
            'matched' => 0,
            'zapped' => 0,
            'ignored' => 0,
            'not_found' => 0,
            'already_blacklisted' => 0,
            'failed' => 0,
            'zapped_items' => [],
            'ignored_items' => [],
            'errors' => [],
        ];

        foreach (array_values($items) as $index => $item) {
            $target = self::blacklistTargetFromItem($item, $mode);

            // Ligne de rapport commune : `index` + `type` / `key` + `name` ou
            // `licence`. Les compteurs la complètent selon le cas.
            $row = ['index' => $index] + self::blacklistReportTarget($target);

            try {
                if ($target === null) {
                    throw new InvalidArgumentException($mode === self::BLACKLIST_MODE_LICENCE
                        ? 'Licence manquante : « licence » (ou « Licence (propre) »).'
                        : 'Nom manquant : « name » (ou « enterprise_name »).');
                }

                $step = DB::transaction(fn () => $target['type'] === self::BLACKLIST_MODE_LICENCE
                    ? self::blacklistByLicence($target['key'])
                    : self::blacklistByName($target['key']));

                $result['processed'] += 1;
                $result['matched'] += $step['matched'];
                $result['zapped'] += $step['blacklisted'];

                if ($step['matched'] === 0) {
                    // Cible introuvable : **ignorée**, pas une erreur — le
                    // lot continue avec l'item suivant.
                    $result['ignored'] += 1;
                    $result['not_found'] += 1;
                    $result['ignored_items'][] = $row + [
                        'reason' => 'not_found',
                    ];
                } elseif ($step['blacklisted'] > 0) {
                    $result['zapped_items'][] = $row + [
                        'matched' => $step['matched'],
                        'zapped' => $step['blacklisted'],
                    ];
                } else {
                    // Déjà en liste noire : sans effet (ré-exécution).
                    $result['ignored'] += 1;
                    $result['already_blacklisted'] += 1;
                    $result['ignored_items'][] = $row + [
                        'reason' => 'already_blacklisted',
                    ];
                }
            } catch (QueryException $e) {
                // Le SQL n'est jamais exposé au client du webhook : il est
                // journalisé côté serveur (contrat des autres bulk publics).
                Log::warning('Conversion publique en liste noire : conflit de données.', [
                    'index' => $index,
                    'error' => $e->getMessage(),
                ]);

                $result['failed'] += 1;
                $result['errors'][] = $row + [
                    'error' => 'Conflit de données : mise en liste noire impossible.',
                ];
            } catch (Throwable $e) {
                $result['failed'] += 1;
                $result['errors'][] = $row + [
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * Ligne de rapport commune à `zapped_items[]` / `ignored_items[]` :
     * `type`, `key`, puis `name` **ou** `licence` (l'autre valant `null`).
     *
     * @param  ?array{type: string, key: string}  $target
     * @return array<string, mixed>
     */
    private static function blacklistReportTarget(?array $target): array
    {
        $type = $target['type'] ?? null;
        $key = $target['key'] ?? null;

        return [
            'type' => $type,
            'key' => $key,
            'name' => $type === self::BLACKLIST_MODE_NAME ? $key : null,
            'licence' => $type === self::BLACKLIST_MODE_LICENCE ? $key : null,
        ];
    }

    /**
     * Cible d'un item : `{type, key}` ou `null` (item inexploitable → `failed`).
     *
     * @return array{type: string, key: string}|null
     */
    private static function blacklistTargetFromItem(mixed $item, string $mode): ?array
    {
        if ($mode === self::BLACKLIST_MODE_LICENCE) {
            $licence = self::blacklistLicenceFromItem($item);

            return $licence === null
                ? null
                : ['type' => self::BLACKLIST_MODE_LICENCE, 'key' => $licence];
        }

        if ($mode === self::BLACKLIST_MODE_AUTO) {
            // Mode auto, contrat du scraper : une **clé de licence** fait
            // basculer l'item en licence. Sinon le nom prime — une chaîne
            // nue reste un nom (historique de l'endpoint) — et, si aucun
            // client ne porte ce nom, la valeur est réessayée **comme
            // licence** (`"1100357101"` d'une liste de licences).
            $licence = is_array($item) ? self::blacklistLicenceFromItem($item) : null;
            $name = self::blacklistNameFromItem($item);

            if ($licence !== null) {
                return ['type' => self::BLACKLIST_MODE_LICENCE, 'key' => $licence];
            }

            if ($name === null) {
                return null;
            }

            if (! self::clientsMatchingName($name)->isEmpty()) {
                return ['type' => self::BLACKLIST_MODE_NAME, 'key' => $name];
            }

            $fallback = self::blacklistLicenceFromItem($item);

            return $fallback === null
                ? ['type' => self::BLACKLIST_MODE_NAME, 'key' => $name]
                : ['type' => self::BLACKLIST_MODE_LICENCE, 'key' => $fallback];
        }

        $name = self::blacklistNameFromItem($item);

        return $name === null
            ? null
            : ['type' => self::BLACKLIST_MODE_NAME, 'key' => $name];
    }

    /**
     * Licence portée par un item : chaîne nue → elle-même, objet → première
     * clé de licence reconnue (`BLACKLIST_LICENCE_KEYS`). La valeur est
     * nettoyée comme à l'import (`cleanText()`) pour viser la forme stockée.
     */
    private static function blacklistLicenceFromItem(mixed $item): ?string
    {
        if (is_scalar($item)) {
            return Client::cleanText($item);
        }

        if (! is_array($item)) {
            return null;
        }

        foreach ($item as $key => $value) {
            if (! in_array(self::normalizePayloadKey((string) $key), self::BLACKLIST_LICENCE_KEYS, true)) {
                continue;
            }

            $licence = Client::cleanText($value);

            if ($licence !== null) {
                return $licence;
            }
        }

        return null;
    }

    /**
     * Lignes répondant au nom fourni (insensible à la casse) puis geste
     * « liste noire » (voir `applyBlacklist()`).
     *
     * @return array{matched: int, blacklisted: int, already: int}
     */
    private static function blacklistByName(string $name): array
    {
        return self::applyBlacklist(self::clientsMatchingName($name), $name);
    }

    /**
     * Lignes correspondant à la licence fournie puis même geste
     * « liste noire » (voir `applyBlacklist()`).
     *
     * @return array{matched: int, blacklisted: int, already: int}
     */
    private static function blacklistByLicence(string $licence): array
    {
        return self::applyBlacklist(self::clientsMatchingLicence($licence), 'licence '.$licence);
    }

    /**
     * Lignes correspondant à une licence — `licence_propre_numero`
     * (numéro RBQ, colonne `integer`) **puis** `licence_number` (texte).
     * Valeur numérique pure → les deux colonnes sont visées ; sinon seule la
     * colonne texte (une chaîne comme `RB-1234` ne doit pas valider un
     * numéro RBQ qui lui ressemble).
     *
     * @return Collection<int, Client>
     */
    private static function clientsMatchingLicence(string $licence): Collection
    {
        return Client::query()
            ->where(function (Builder $query) use ($licence) {
                if (ctype_digit($licence)) {
                    $query->where('licence_propre_numero', (int) $licence)
                        ->orWhere('licence_number', $licence);
                } else {
                    $query->where('licence_number', $licence);
                }
            })
            ->get();
    }

    /**
     * Lignes **contenant** le nom fourni — `LOWER(colonne) LIKE
     * '%nom%'`, donc **insensible à la casse** et **partielle** : une saisie
     * partielle (`Forget`) atteint toutes les entreprises qui la contiennent.
     * C'est le comportement historique de l'endpoint par nom ; le mode
     * licence (lui) est une égalité exacte.
     *
     * @return Collection<int, Client>
     */
    private static function clientsMatchingName(string $name): Collection
    {
        return Client::query()
            ->where(function (Builder $query) use ($name) {
                $searchTerm = '%'.strtolower($name).'%';
                $query->whereRaw('LOWER(enterprise_name) LIKE ?', [$searchTerm])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$searchTerm]);
            })
            ->get();
    }

    /**
     * Geste « liste noire » appliqué aux lignes déjà résolues (§3.4 — cas 6) :
     * `is_blacklisted = true`, `status = BLACKLISTED`, `returned_at` vidé,
     * rappels annulés, note `BLACKLISTED` journalisée (émetteur `SYSTEM` :
     * l'API publique n'a pas d'utilisateur). Les réservations sont
     * **conservées** (la liste noire ne supprime pas l'historique, §3.4).
     *
     * Une ligne déjà en liste noire n'est ni réécrite ni re-journalisée :
     * elle compte dans `already` (`updated_at` ne bouge pas).
     *
     * @param  Collection<int, Client>  $clients
     * @param  string  $label  nom ou licence visée, reprise dans la note
     * @return array{matched: int, blacklisted: int, already: int}
     */
    private static function applyBlacklist(Collection $clients, string $label): array
    {
        $matched = $clients->count();
        $blacklisted = 0;
        $already = 0;

        foreach ($clients as $client) {
            if ((bool) $client->is_blacklisted && $client->status === Client::STATUS_BLACKLISTED) {
                $already += 1;

                continue;
            }

            $client->update([
                'is_blacklisted' => true,
                'status' => Client::STATUS_BLACKLISTED,
                'returned_at' => null,
            ]);

            // Rappels annulés, réservations conservées (§3.4 / §5.2).
            Rappel::where('client_id', $client->id)->delete();

            Note::create([
                'client_id' => $client->id,
                'sender_id' => Note::SENDER_SYSTEM,
                'type' => Note::TYPE_BLACKLISTED,
                'description' => 'Liste noire (API publique) : '.$label,
            ]);

            $blacklisted += 1;
        }

        return ['matched' => $matched, 'blacklisted' => $blacklisted, 'already' => $already];
    }

    /**
     * Nom porté par un item de conversion : chaîne nue → le nom lui-même,
     * objet → première clé de nom reconnue (`BLACKLIST_NAME_KEYS`).
     *
     * La valeur est nettoyée comme à l'import (`cleanText()` : espaces
     * réduits, quotes de protection retirées) pour viser la forme stockée.
     */
    private static function blacklistNameFromItem(mixed $item): ?string
    {
        if (is_scalar($item)) {
            return Client::cleanText($item);
        }

        if (! is_array($item)) {
            return null;
        }

        foreach ($item as $key => $value) {
            if (! in_array(self::normalizePayloadKey((string) $key), self::BLACKLIST_NAME_KEYS, true)) {
                continue;
            }

            $name = Client::cleanText($value);

            if ($name !== null) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Clés d'item acceptées pour un numéro de téléphone — forme normalisée
     * (`self::normalizePayloadKey()`), donc insensible à la casse et à la
     * ponctuation : `phone`, `telephone`, `Téléphone` → `tlphone`, `tel`,
     * `cell` / `cellulaire`, `numero` / `numéro` → `numro`.
     */
    private const PHONE_ITEM_KEYS = [
        'phone',
        'telephone',
        'tlphone',
        'tel',
        'cell',
        'cellulaire',
        'cellnumber',
        'mobileno',
        'numero',
        'numro',
    ];

    /**
     * Indisponibilité **en masse par numéro de téléphone** : chaque numéro
     * du lot fait basculer **tous** les clients qui le portent en
     * `UNAVAILABLE` avec `returned_at = now + 3 mois` — le geste métier du
     * « NON » (docs/RULES.md §3), durée portée par la constante unique
     * `(int) config('rules.non_block_months', 3)`.
     *
     * **Détection du numéro** (`normalizePhone()`) : les formats
     * `819-418-6550`, `8194186550`, `(819) 418 6550`, `+1819-418-6550`,
     * `+18194186550`, `+1-819-418-6550`… visent tous le même numéro, des
     * deux côtés (numéro saisi **et** colonne `phone` stockée, y compris
     * avec extension). Un même numéro peut viser plusieurs lignes :
     * **toutes** sont passées en indisponible.
     *
     * **Rapport consolidé** :
     *
     *   processed         numéros traités **sans erreur** (= succès)
     *   matched           lignes clients trouvées
     *   blocked           lignes réellement passées en UNAVAILABLE 3 mois
     *   ignored           numéros **sans effet** → `ignored_items[]`
     *   not_found         numéros introuvables (dans `ignored`)
     *   already_unavailable lignes déjà indisponibles (compteur de lignes)
     *   blacklisted       lignes en liste noire non rétrogradées (compteur de lignes)
     *   failed            numéros en échec → `errors[]`
     *
     * Contrats (mêmes mots que `convertToBlacklistFromName()`) :
     *
     *  - un numéro **inconnu n'est pas une erreur** : `not_found`, le lot
     *    continue ;
     *  - une ligne **déjà `UNAVAILABLE`** avec un `returned_at` futur n'est
     *    ni réécrite ni re-journalisée (`already_unavailable` :
     *    `updated_at` figé) — le lot est **ré-exécutable** ;
     *  - une ligne **en liste noire n'est jamais rétrogradée** en
     *    indisponibilité temporaire (`blacklisted`) ;
     *  - les **réservations sont conservées** (l'historique ne disparaît
     *    pas) mais les **rappels sont annulés** : sans cela, le cron des
     *    rappels expirés ré-appliquerait son propre blocage et écraserait
     *    le retour à 3 mois ;
     *  - chaque changement est journalisé par une note `NOTE` émise par
     *    `SYSTEM` (l'API n'a pas d'utilisateur).
     *
     * Chaque numéro est traité **dans sa propre transaction** : un item
     * invalide est compté dans `failed` sans annuler le reste du lot — même
     * contrat que `bulkUpsertFromScraperPayload()`.
     *
     * @param  list<mixed>  $items  chaîne nue (`"819-418-6550"`) ou objet
     *                              (`{"phone": "…"}`, cf. `PHONE_ITEM_KEYS`)
     * @return array{received:int, processed:int, matched:int, blocked:int, ignored:int, not_found:int, already_unavailable:int, blacklisted:int, failed:int, blocked_items:list<array{index:int, phone:string, matched:int, blocked:int, returned_at:string}>, ignored_items:list<array{index:int, phone:string, reason:string}>, errors:list<array{index:int, phone:?string, error:string}>}
     */
    public static function bulkUnavailableFromPhone(array $items): array
    {
        $result = [
            'received' => count($items),
            'processed' => 0,
            'matched' => 0,
            'blocked' => 0,
            'ignored' => 0,
            'not_found' => 0,
            'already_unavailable' => 0,
            'blacklisted' => 0,
            'failed' => 0,
            'blocked_items' => [],
            'ignored_items' => [],
            'errors' => [],
        ];

        foreach (array_values($items) as $index => $item) {
            $phone = self::phoneFromItem($item);

            try {
                if ($phone === null) {
                    throw new InvalidArgumentException(
                        'Numéro manquant : « phone » (ou item fourni en chaîne nue).'
                    );
                }

                $normalized = Client::normalizePhone($phone);

                if ($normalized === null) {
                    throw new InvalidArgumentException(
                        'Numéro illisible : aucun chiffre détecté (ex. « 819-418-6550 »).'
                    );
                }

                $step = DB::transaction(fn () => self::unavailableByPhone($normalized, $phone));

                $result['processed'] += 1;
                $result['matched'] += $step['matched'];
                $result['blocked'] += $step['blocked'];
                $result['already_unavailable'] += $step['already'];
                $result['blacklisted'] += $step['blacklisted'];

                if ($step['matched'] === 0) {
                    // Numéro introuvable : **ignoré**, pas une erreur — le
                    // lot continue avec le numéro suivant.
                    $result['ignored'] += 1;
                    $result['not_found'] += 1;
                    $result['ignored_items'][] = [
                        'index' => $index,
                        'phone' => $phone,
                        'reason' => 'not_found',
                    ];
                } elseif ($step['blocked'] === 0) {
                    // Trouvé mais sans effet : déjà indisponible (prioritaire)
                    // ou en liste noire (ré-exécution / non rétrogradation).
                    $result['ignored'] += 1;
                    $result['ignored_items'][] = [
                        'index' => $index,
                        'phone' => $phone,
                        'reason' => $step['already'] > 0 ? 'already_unavailable' : 'blacklisted',
                    ];
                } else {
                    $result['blocked_items'][] = [
                        'index' => $index,
                        'phone' => $phone,
                        'matched' => $step['matched'],
                        'blocked' => $step['blocked'],
                        'returned_at' => $step['returned_at'],
                    ];
                }
            } catch (QueryException $e) {
                // Le SQL n'est jamais exposé au client : il est journalisé
                // côté serveur (contrat des autres bulk publics).
                Log::warning('Indisponibilité publique par téléphone : conflit de données.', [
                    'index' => $index,
                    'error' => $e->getMessage(),
                ]);

                $result['failed'] += 1;
                $result['errors'][] = [
                    'index' => $index,
                    'phone' => $phone,
                    'error' => 'Conflit de données : indisponibilité impossible.',
                ];
            } catch (Throwable $e) {
                $result['failed'] += 1;
                $result['errors'][] = [
                    'index' => $index,
                    'phone' => $phone,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * Passe en `UNAVAILABLE` **tous** les clients portant le numéro fourni
     * (comparaison sur la clé normalisée) : `status = UNAVAILABLE`,
     * `returned_at = now + 3 mois` (règle NO), rappels annulés, réservations
     * conservées, note `NOTE` émise par `SYSTEM`.
     *
     * Une ligne **déjà indisponible** (retour futur) ou **en liste noire**
     * n'est ni réécrite ni re-journalisée : elle compte dans `already` /
     * `blacklisted`.
     *
     * @return array{matched: int, blocked: int, already: int, blacklisted: int, returned_at: ?string}
     */
    private static function unavailableByPhone(string $normalized, string $phone): array
    {
        $clients = self::clientsByPhone($normalized);

        $step = [
            'matched' => $clients->count(),
            'blocked' => 0,
            'already' => 0,
            'blacklisted' => 0,
            'returned_at' => null,
        ];

        if ($clients->isEmpty()) {
            return $step;
        }

        // Règle NO : retour dans 3 mois (durée unique portée par le
        // workflow d'appel — (int) config('rules.non_block_months', 3)).
        $returnedAt = Carbon::now()->addMonths((int) config('rules.non_block_months', 3));
        $step['returned_at'] = $returnedAt->toDateTimeString();

        foreach ($clients as $client) {
            if ((bool) $client->is_blacklisted || $client->status === Client::STATUS_BLACKLISTED) {
                // Une liste noire n'est jamais rétrogradée en simple
                // indisponibilité temporaire.
                $step['blacklisted'] += 1;

                continue;
            }

            if ($client->status === Client::STATUS_UNAVAILABLE
                && $client->returned_at !== null
                && $client->returned_at->isFuture()) {
                $step['already'] += 1;

                continue;
            }

            $client->update([
                'status' => Client::STATUS_UNAVAILABLE,
                'returned_at' => $returnedAt,
            ]);

            // Rappels annulés : le cron des rappels expirés ré-appliquerait
            // son propre blocage (21 j) et écraserait le retour à 3 mois.
            // Réservations conservées (la liste noire / l'indisponibilité ne
            // suppriment jamais l'historique, §3.4).
            Rappel::where('client_id', $client->id)->delete();

            Note::create([
                'client_id' => $client->id,
                'sender_id' => Note::SENDER_SYSTEM,
                'type' => Note::TYPE_NOTE,
                'description' => 'Indisponible 3 mois (import téléphone) : '.$phone,
            ]);

            $step['blocked'] += 1;
        }

        return $step;
    }

    /**
     * Clients dont le numéro correspond **exactement** à la clé normalisée
     * fournie — deux passages, portable MySQL / SQLite (AGENTS.md §2) :
     *
     *  1. pré-filtre SQL sur `phone` privée de sa ponctuation
     *     (`digitsOnlySql()`, `LIKE`) pour ne jamais charger la table ;
     *  2. vérification exacte en PHP par `normalizePhone()` (extension,
     *     indicatif 1, formes non couvertes par les REPLACE SQL).
     *
     * @return Collection<int, Client>
     */
    private static function clientsByPhone(string $normalized)
    {
        $query = Client::query()->whereNotNull('phone');
        $phone = $query->getQuery()->getGrammar()->wrap('phone');

        return $query
            ->whereRaw(Client::digitsOnlySql($phone).' LIKE ?', ['%'.$normalized.'%'])
            ->get()
            ->filter(fn (Client $client) => Client::normalizePhone($client->phone) === $normalized)
            ->values();
    }

    /**
     * Numéro porté par un item : chaîne nue → le numéro lui-même, objet →
     * première clé de téléphone reconnue (`PHONE_ITEM_KEYS`, forme
     * normalisée, donc insensible à la casse et à la ponctuation).
     */
    private static function phoneFromItem(mixed $item): ?string
    {
        if (is_scalar($item)) {
            return Client::cleanText($item);
        }

        if (! is_array($item)) {
            return null;
        }

        foreach ($item as $key => $value) {
            if (! in_array(self::normalizePayloadKey((string) $key), self::PHONE_ITEM_KEYS, true)) {
                continue;
            }

            $phone = Client::cleanText($value);

            if ($phone !== null) {
                return $phone;
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Fausses réservations « NON » — endpoint public **temporaire**
    // `POST clients/create-no-reservations` (spec :
    // `docs/create_no_reservations_api.md`).
    //
    // Ce geste est une **donnée de préparation** : il écrit une ligne
    // `reservations` avec `status = NO` attribuée à **un seul employé**
    // (`no_reservations_comercial_email`), afin de fabriquer l'historique
    // « cet employé a répondu NON » que le tableau de bord consomme. Il ne
    // déclenche **aucun** effet de bord métier : ni note `NO`, ni
    // `returned_at`, ni auto-liste-noire (RULES §3) — le workflow réel passe
    // par `CallWorkflowService::apply()`.
    // ─────────────────────────────────────────────────────────────────────────────

    /** Cible = identifiant de client (uuid). */
    public const NO_RESERVATION_TARGET_ID = 'id';

    /** Cible = licence (« Licence (propre) », sinon « Licence »). */
    public const NO_RESERVATION_TARGET_LICENCE = 'licence';

    /**
     * Employé unique auquel sont attribuées les fausses réservations : le
     * stock n'est **pas** réparti entre tous les commerciaux (c'est le but
     * de l'endpoint — un historique « NON » côté un seul employé). Surcharge
     * possible par `config('public_api.no_reservations_comercial_email')`.
     */
    public const NO_RESERVATIONS_COMERCIAL_EMAIL = 'mohamed.khemir@apex-structures.tn';

    /**
     * Plafond de clients traités par appel en mode `{"status": …}` (ce mode
     * ignore `public_api.max_items` : il ne reçoit pas de liste du client).
     * Surchargable par `config('public_api.no_reservations_status_limit')`.
     */
    public const NO_RESERVATIONS_STATUS_LIMIT = 20000;

    /**
     * Clés d'item acceptées pour un identifiant de client — forme normalisée
     * (`normalizePayloadKey()`) : `id`, `client_id`, `clientid`, `uuid`.
     */
    private const NO_RESERVATION_ID_KEYS = ['id', 'clientid', 'uuid'];

    /**
     * Fausse réservation « NON » pour les clients d'un lot d'items
     * (identifiant ou licence), une seule cible par item.
     *
     * @param  list<mixed>  $items  uuid nu, licence nu, ou objet
     *                              (`{"client_id": "…"}`, `{"licence": "…"}`)
     * @return array<string, mixed> rapport consolidé (voir §5 de la spec)
     */
    public static function noReservationsFromItems(array $items, string $comercialEmail): array
    {
        $targets = [];

        foreach (array_values($items) as $index => $item) {
            $target = self::noReservationTargetFromItem($item);

            if ($target === null) {
                $targets[] = ['index' => $index, 'type' => null, 'key' => null];
            } else {
                $targets[] = ['index' => $index] + $target;
            }
        }

        return self::noReservationsForTargets($targets, $comercialEmail);
    }

    /**
     * Fausse réservation « NON » pour **tous** les clients portant un statut
     * donné (`{"status": "UNAVAILABLE"}`) — le script de campagne n'a pas
     * la liste des clients sous la main, il ne connaît que le périmètre.
     *
     * Un statut n'est pas modifié par le geste : un second appel reverrait
     * sur les mêmes clients. Le curseur `after` (identifiant **exclu**, tri
     * par `id`) permet donc de dérouler le périmètre en plusieurs appels :
     * `next_after` vaut le dernier identifiant traité tant qu'il reste des
     * clients au-delà du plafond, `null` dès que le périmètre est couvert.
     *
     * @param  string  $status  un `Client::STATUS_*` (casse et espaces
     *                          insensibles)
     * @param  ?string  $after  identifiant exclu (pagination du périmètre)
     * @return array<string, mixed>
     */
    public static function noReservationsFromStatus(
        string $status,
        string $comercialEmail,
        ?string $after = null
    ): array {
        $status = Client::cleanText($status) ?? '';
        $normalized = strtoupper($status);

        if (! in_array($normalized, Client::STATUSES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Statut inconnu : « %s » (attendu : %s).',
                $status,
                implode(' / ', Client::STATUSES)
            ));
        }

        $after = Client::cleanText($after);
        $after = $after === null || $after === '' ? null : $after;

        $limit = max(1, (int) config(
            'public_api.no_reservations_status_limit',
            self::NO_RESERVATIONS_STATUS_LIMIT
        ));

        // On ne charge que les identifiants (une campagne « indisponibles »
        // peut viser plusieurs milliers de clients) et on plafonne :
        // `next_after` invite le script à reprendre au-delà.
        $query = Client::query()->where('status', $normalized);

        if ($after !== null) {
            $query->where('id', '>', $after);
        }

        $ids = $query
            ->orderBy('id')
            ->limit($limit + 1)
            ->pluck('id');

        $truncated = $ids->count() > $limit;
        $page = $ids->take($limit);

        $targets = $page
            ->map(fn (string $id) => [
                'index' => null,
                'type' => self::NO_RESERVATION_TARGET_ID,
                'key' => $id,
            ])
            ->all();

        $result = self::noReservationsForTargets($targets, $comercialEmail);
        $result['truncated'] = $truncated;
        $result['next_after'] = $truncated ? $page->last() : null;

        return $result;
    }

    /**
     * Boucle commune : une cible → les lignes clients qu'elle désigne → une
     * fausse réservation `NO` par ligne, dans la transaction de la cible.
     *
     * Cibles ignorées (sans échec) : client introuvable (`not_found`),
     * client déjà porteur d'une réservation `NO` de cet employé
     * (`already_no`), client en liste noire (`skipped`, `reason =
     * blacklisted` — une réservation « NON » n'a pas de sens sur un client
     * définitivement exclu). Seuls les items **inexploitables** (aucune clé
     * d'identifiant ni de licence) sont des erreurs.
     *
     * @param  list<array{index: ?int, type: ?string, key: ?string}>  $targets
     * @return array<string, mixed>
     */
    private static function noReservationsForTargets(array $targets, string $comercialEmail): array
    {
        $result = [
            'received' => count($targets),
            'processed' => 0,
            'matched' => 0,
            'created' => 0,
            'already' => 0,
            'skipped' => 0,
            'not_found' => 0,
            'failed' => 0,
            'comercial_email' => $comercialEmail,
            // `truncated` : le périmètre demandé dépassait le plafond
            // (`true` uniquement en mode `{"status": …}`, cf.
            // `NO_RESERVATIONS_STATUS_LIMIT`). `next_after` : curseur du
            // prochain passage dans ce mode, `null` sinon.
            'truncated' => false,
            'next_after' => null,
            'created_items' => [],
            'ignored_items' => [],
            'errors' => [],
        ];

        $comercial = User::where('email', $comercialEmail)->first();

        if ($comercial === null) {
            throw new InvalidArgumentException(sprintf(
                'Employé introuvable : « %s » (base de données de développement non semée ?).',
                $comercialEmail
            ));
        }

        foreach ($targets as $target) {
            // Ligne de rapport : `index` + cible. Les valeurs propres à la
            // ligne cliente (`client_id`, nom, licence) **complètent** cette
            // base via `array_merge()` — `+` ne les écraserait pas.
            $row = [
                'index' => $target['index'],
                'type' => $target['type'],
                'key' => $target['key'],
            ];

            try {
                if ($target['key'] === null || $target['type'] === null) {
                    throw new InvalidArgumentException(
                        'Cible manquante : « client_id » (ou « licence »).'
                    );
                }

                $step = DB::transaction(
                    fn () => self::noReservationByTarget($comercial, $target['type'], $target['key'])
                );

                $result['processed'] += 1;
                $result['matched'] += $step['matched'];
                $result['created'] += count($step['created']);
                $result['already'] += $step['already'];
                $result['skipped'] += $step['skipped'];
                $result['not_found'] += $step['matched'] === 0 ? 1 : 0;

                foreach ($step['created'] as $client) {
                    $result['created_items'][] = array_merge($row, [
                        'client_id' => $client['client_id'],
                        'enterprise_name' => $client['enterprise_name'],
                        'licence_number' => $client['licence_number'],
                        'reservation_id' => $client['reservation_id'],
                        'client_status' => $client['client_status'],
                    ]);
                }

                // Sans effet : introuvable, déjà « NON », ou liste noire.
                foreach ($step['ignored'] as $ignored) {
                    $result['ignored_items'][] = array_merge($row, [
                        'client_id' => $ignored['client_id'],
                        'enterprise_name' => $ignored['enterprise_name'],
                        'licence_number' => $ignored['licence_number'],
                        'reason' => $ignored['reason'],
                    ]);
                }
            } catch (InvalidArgumentException $e) {
                $result['failed'] += 1;
                $result['errors'][] = array_merge($row, ['error' => $e->getMessage()]);
            } catch (Throwable $e) {
                Log::warning('Fausse réservation NON (API publique) : échec.', [
                    'target' => $target['key'],
                    'error' => $e->getMessage(),
                ]);

                $result['failed'] += 1;
                $result['errors'][] = array_merge($row, ['error' => $e->getMessage()]);
            }
        }

        return $result;
    }

    /**
     * Une cible → ses clients → les fausses réservations correspondantes.
     *
     * @return array{matched: int, created: list<array<string, mixed>>, ignored: list<array<string, mixed>>, already: int, skipped: int}
     */
    private static function noReservationByTarget(User $comercial, string $type, string $key): array
    {
        $clients = $type === self::NO_RESERVATION_TARGET_ID
            ? Client::query()->whereKey($key)->get()
            : self::clientsMatchingLicence($key);

        $created = [];
        $ignored = [];
        $already = 0;
        $skipped = 0;

        foreach ($clients as $client) {
            $descriptor = [
                'client_id' => $client->id,
                'enterprise_name' => $client->enterprise_name ?? $client->name,
                'licence_number' => $client->licence_number,
            ];

            if ((bool) $client->is_blacklisted || $client->status === Client::STATUS_BLACKLISTED) {
                $skipped += 1;
                $ignored[] = $descriptor + ['reason' => 'blacklisted'];

                continue;
            }

            if ($client->reservations()
                ->where('comercial_id', $comercial->id)
                ->where('status', Reservation::STATUS_NO)
                ->exists()
            ) {
                $already += 1;
                $ignored[] = $descriptor + ['reason' => 'already_no'];

                continue;
            }

            // `reservation_group_id` reste NULL : une fausse réservation
            // n'appartient à aucune liste d'un employé (aucune liste ne doit
            // apparaître dans « Mes listes »).
            $reservation = Reservation::create([
                'client_id' => $client->id,
                'comercial_id' => $comercial->id,
                'status' => Reservation::STATUS_NO,
            ]);

            $created[] = $descriptor + [
                'reservation_id' => $reservation->id,
                'client_status' => $client->status,
            ];
        }

        return [
            'matched' => $clients->count(),
            'created' => $created,
            'ignored' => $ignored,
            'already' => $already,
            'skipped' => $skipped,
        ];
    }

    /**
     * Cible d'un item : identifiant (`NO_RESERVATION_ID_KEYS`) **puis**
     * licence (`BLACKLIST_LICENCE_KEYS`), ou `null` si l'item n'en porte
     * aucune → l'item est compté dans `failed`, le lot continue.
     *
     * @return array{type: string, key: string}|null
     */
    private static function noReservationTargetFromItem(mixed $item): ?array
    {
        if (is_scalar($item) && ! is_bool($item)) {
            $value = Client::cleanText($item);

            if ($value === null) {
                return null;
            }

            // Un uuid est un identifiant, tout le reste une licence.
            return Str::isUuid($value)
                ? ['type' => self::NO_RESERVATION_TARGET_ID, 'key' => $value]
                : ['type' => self::NO_RESERVATION_TARGET_LICENCE, 'key' => $value];
        }

        if (! is_array($item)) {
            return null;
        }

        foreach ($item as $key => $value) {
            if (in_array(self::normalizePayloadKey((string) $key), self::NO_RESERVATION_ID_KEYS, true)) {
                $id = Client::cleanText($value);

                if ($id !== null) {
                    return ['type' => self::NO_RESERVATION_TARGET_ID, 'key' => $id];
                }
            }
        }

        $licence = self::blacklistLicenceFromItem($item);

        return $licence === null
            ? null
            : ['type' => self::NO_RESERVATION_TARGET_LICENCE, 'key' => $licence];
    }

    /**
     * Clé d'identification d'un item de suppression : chaîne nue → numéro de
     * licence, objet payload → `licence_number` puis repli
     * `licence_propre_numero` (mêmes règles que l'import).
     *
     * @return array<string, mixed> vide si aucune clé n'est fournie
     */
    private static function scraperLookupKey(mixed $item): array
    {
        if (is_scalar($item)) {
            $licence = trim((string) $item);

            return $licence === '' ? [] : ['licence_number' => $licence];
        }

        if (! is_array($item)) {
            return [];
        }

        $attributes = self::attributesFromPayload($item);

        $licence = $attributes['licence_number'] ?? null;

        if (is_string($licence) && $licence !== '') {
            return ['licence_number' => $licence];
        }

        if (($attributes['licence_propre_numero'] ?? null) !== null) {
            return ['licence_propre_numero' => $attributes['licence_propre_numero']];
        }

        return [];
    }

    /** Clé payload → forme normalisée (minuscules, sans ponctuation). */
    private static function normalizePayloadKey(string $key): string
    {
        return preg_replace('/[^a-z0-9]+/u', '', mb_strtolower($key)) ?? '';
    }

    /** Alignement d'une valeur de payload sur le cast de la colonne cible. */
    private static function castPayloadValue(string $column, mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === null || $value === '') {
            return null;
        }

        return match ($column) {
            'licence_propre_numero', 'respondent_count', 'sub_category_count' => (int) $value,
            'surety_amount' => self::cleanAmount($value),
            'licence_start_date', 'licence_end_date' => self::payloadDate($value),
            // Liste JSON / tableau natif / chaîne séparée : `scrubAttributes()`
            // en fait un tableau (`cautionnement_compagnie`) + la 1re valeur (`surety_company`).
            'respondents', 'authorized_categories', 'cautionnement_compagnie', 'surety_company' => self::payloadArray($value),
            default => is_scalar($value) ? (string) $value : null,
        };
    }

    /** Montant : nombre natif (int/float), chaîne brute ("20000"), formatée ("20 000 $", "20,000.00"). */
    private static function cleanAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $cleaned = trim($value);

            if (is_numeric($cleaned)) {
                return (float) $cleaned;
            }

            // Retrait des symboles monétaires, espaces et insécables
            $cleaned = preg_replace('/[\s\x{00A0}$€CAD]+/u', '', $cleaned) ?? '';

            if (preg_match('/^\d+,\d{1,2}$/', $cleaned)) {
                $cleaned = str_replace(',', '.', $cleaned);
            } else {
                $cleaned = str_replace(',', '', $cleaned);
            }

            return is_numeric($cleaned) ? (float) $cleaned : null;
        }

        return null;
    }

    /** Date ISO n8n (`2025-05-28T00:00:00`) → `Y-m-d`, illisible → NULL. */
    private static function payloadDate(mixed $value): ?string
    {
        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Nettoyage des attributs mappés **avant** écriture (docs/RULES.md §12).
     *
     * Le webhook public n'impose aucune validation (choix projet) : il
     * normalise à la place, pour que ce qui arrive par n8n / scraper soit
     * présentable tel quel dans les vues et les filtres.
     *
     *  - textes        : espaces (dont insécables) réduits, quotes retirées ;
     *  - libellés      : code de tête `[1.23]` / `1.23 — ` / `ADM — ` retiré,
     *                    casse MAJUSCULE ramenée en casse normale (accents et
     *                    casse existante conservés) ;
     *  - téléphone     : `5143535820 Ext.: 5417` → `514-353-5820 ext. 5417` ;
     *  - courriel      : validé + minuscules, invalide → NULL ;
     *  - listes        : items nettoyés + doublons retirés (insensibles à la
     *                    casse) puis stockées en JSON ;
     *  - cautionnement : `cautionnement_compagnie` (JSON) + 1re valeur dans
     *                    `surety_company` (string historique).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function scrubAttributes(array $attributes): array
    {
        foreach (['licence_number', 'enterprise_name', 'intervenant_name',
            'licence_status', 'full_address'] as $column) {
            if (array_key_exists($column, $attributes)) {
                $attributes[$column] = Client::cleanText($attributes[$column]);
            }
        }

        foreach (['municipality', 'administrative_region'] as $column) {
            if (array_key_exists($column, $attributes)) {
                $attributes[$column] = self::cleanLabel($attributes[$column]);
            }
        }

        if (array_key_exists('neq', $attributes)) {
            $attributes['neq'] = self::cleanNeq($attributes['neq']);
        }

        if (array_key_exists('phone', $attributes)) {
            $attributes['phone'] = Client::cleanPhone($attributes['phone']);
        }

        if (array_key_exists('email', $attributes)) {
            $attributes['email'] = self::cleanEmail($attributes['email']);
        }

        // Source : libellé du répertoire `sources`, colonne NOT NULL — une
        // valeur vide / absente **retire la clé** : la création garde alors
        // `Client::DEFAULT_SOURCE` (« Affaire ») et la mise à jour laisse la
        // valeur déjà en place (jamais de NULL dans la colonne).
        if (array_key_exists('source', $attributes)) {
            $source = self::cleanLabel($attributes['source']);

            if ($source === null || $source === '') {
                unset($attributes['source']);
            } else {
                $attributes['source'] = $source;
            }
        }

        foreach (['respondents', 'authorized_categories'] as $column) {
            if (array_key_exists($column, $attributes)) {
                $attributes[$column] = self::cleanList($attributes[$column]);
            }
        }

        // Cautionnement : tableau JSON / natif + le string historique (1re valeur).
        if (array_key_exists('surety_company', $attributes) || array_key_exists('cautionnement_compagnie', $attributes)) {
            $source = $attributes['cautionnement_compagnie'] ?? $attributes['surety_company'];
            $companies = self::cleanList($source);
            $attributes['cautionnement_compagnie'] = $companies;
            $attributes['surety_company'] = $companies[0] ?? null;
        }

        return $attributes;
    }

    /** Texte + code de tête retiré + casse MAJUSCULE ramenée en casse normale. */
    private static function cleanLabel(mixed $value): ?string
    {
        $text = Client::cleanText($value);

        if ($text === null) {
            return null;
        }

        // `[1.23] Libellé`, `[GPC] Libellé`
        $text = preg_replace('/^\[[^\]]{1,24}\]\s*/u', '', $text) ?? $text;
        // `1.23 — Libellé`, `ADM - Libellé` (code numérique ou sigle seulement)
        $text = preg_replace('/^(?:\d+(?:\.\d+)*|[A-Z]{2,}[0-9]*)\s*[—–-]{1,2}\s+/u', '', $text) ?? $text;

        // « MONTREAL » → « Montreal » (les libellés déjà en casse normale,
        // accents compris, ne sont pas touchés).
        if (mb_strlen($text) > 3
            && mb_strtoupper($text, 'UTF-8') === $text
            && preg_match('/\p{L}{2,}/u', $text)) {
            $text = mb_convert_case($text, MB_CASE_TITLE, 'UTF-8');
        }

        $text = trim($text, " \t\n\r\0\x0B,;:");

        return $text === '' ? null : $text;
    }

    /** NEQ : chiffres seuls quand la valeur n'en contient qu'à eux. */
    private static function cleanNeq(mixed $value): ?string
    {
        $text = Client::cleanText($value);

        if ($text === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $text) ?? '';

        return $digits !== '' ? $digits : $text;
    }

    /** Courriel validé : minuscules, 1re adresse valide, sinon NULL. */
    private static function cleanEmail(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $text = mb_strtolower(trim(preg_replace('/[\x{00A0}\s]+/u', ' ', (string) $value) ?? ''), 'UTF-8');
        $text = preg_replace('/^mailto:/', '', $text) ?? $text;

        if ($text === '') {
            return null;
        }

        foreach (preg_split('/[,;]|\s+/', $text) ?: [] as $candidate) {
            $candidate = trim($candidate, '.,;()<>');

            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Liste (tableau PHP / chaîne JSON / chaîne séparée) → items nettoyés,
     * vides retirés et doublons éliminés (insensibles à la casse).
     *
     * Accepte tous les formats :
     *  - données déjà propres : `["Jean Dupont", "Marie Curie"]`
     *  - données brutes en chaîne JSON : `'["[1.23] Électricité", "ÉLECTRICITÉ"]'`
     *  - chaîne séparée par pipe : `"Catégorie 1 | Catégorie 2"`
     *  - objets structurés : `[['name' => 'Jean', 'role' => 'Sécurité']]`
     *
     * @return list<string>
     */
    private static function cleanList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded)
                ? $decoded
                : (str_contains($value, '|') ? array_map('trim', explode('|', $value)) : [$value]);
        }

        if (! is_array($value)) {
            $value = $value === null ? [] : [$value];
        }

        $cleaned = [];
        $seen = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $name = $item['name'] ?? $item['nom'] ?? null;
                $qual = $item['qualification'] ?? $item['role'] ?? null;
                if ($name && $qual) {
                    $item = "{$name} ({$qual})";
                } elseif ($name) {
                    $item = (string) $name;
                } else {
                    $item = implode(' ', array_filter(array_map('strval', $item)));
                }
            }

            $label = self::cleanLabel($item);

            if ($label === null) {
                continue;
            }

            $key = mb_strtolower($label, 'UTF-8');

            if (isset($seen[$key])) {
                continue; // doublon
            }

            $seen[$key] = true;
            $cleaned[] = $label;
        }

        return array_values($cleaned);
    }

    /** Tableau JSON n8n / tableau PHP / chaîne brute → tableau PHP. */
    private static function payloadArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return array_values($decoded);
            }

            $value = trim($value);

            if ($value === '') {
                return [];
            }

            if (str_contains($value, ' | ')) {
                return array_map('trim', explode(' | ', $value));
            }

            if (str_contains($value, '|')) {
                return array_map('trim', explode('|', $value));
            }

            return [$value];
        }

        return [];
    }
}
