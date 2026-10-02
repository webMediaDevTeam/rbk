<?php

namespace App\Models;

use App\Services\CallWorkflowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class Client extends Model
{
    use HasFactory, HasUuids;

    // `created_at` + `updated_at` : Eloquent maintient `updated_at` à chaque
    // modification (colonnes `timestamp` gérées par le modèle, pas par la DB).

    protected $table = 'clients';

    // ------------------------------------------------------------------
    // Statuts du client (docs/models.puml)
    // ------------------------------------------------------------------

    /** Disponible à la réservation (scopeAvailable). */
    public const STATUS_AVAILABLE = 'AVAILABLE';

    /** Réservé par un employé : appel en cours. */
    public const STATUS_RESERVED = 'RESERVED';

    /**
     * DÉRIVÉ — affichage uniquement, **jamais stocké** : la dernière
     * réservation est un BV_VOICEMAIL ou un CALL_BACK.
     */
    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';

    /** Indisponible temporairement pour tous (NO : 3 mois / 3e BV : 21 j). */
    public const STATUS_UNAVAILABLE = 'UNAVAILABLE';

    /** Liste noire (`is_blacklisted = true`). */
    public const STATUS_BLACKLISTED = 'BLACKLISTED';

    /** Confirmé (issue YES) — définitif jusqu'à clôture admin. */
    public const STATUS_CONFIRMED = 'CONFIRMED';

    /** Valeurs stockées dans `clients.status` (IN_PROGRESS en est exclu). */
    public const STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_RESERVED,
        self::STATUS_UNAVAILABLE,
        self::STATUS_BLACKLISTED,
        self::STATUS_CONFIRMED,
    ];

    protected $fillable = [
        'name',
        'enterprise_name',
        'categories',
        'status',
        // Pointeur « réservation courante » : écrit uniquement par
        // `syncCurrentReservation()` (jamais par un update applicatif).
        'current_reservation_id',
        'current_comercial_id',
        'is_blacklisted',
        'returned_at',
        'licence_number',
        'licence_propre',
        'licence_propre_numero',
        'intervenant_name',
        'licence_status',
        'neq',
        'full_address',
        'municipality',
        'administrative_region',
        'phone',
        'email',
        'respondent_count',
        'respondents',
        'sub_category_count',
        'authorized_categories',
        'surety_company',
        'cautionnement_compagnie',
        'surety_amount',
        'licence_start_date',
        'licence_end_date',
        'representative_name',
    ];

    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'is_blacklisted' => 'boolean',
            'licence_propre' => 'boolean',
            'licence_propre_numero' => 'integer',
            'respondents' => 'array',
            'authorized_categories' => 'array',
            'cautionnement_compagnie' => 'array',
            'surety_amount' => 'decimal:2',
            'licence_start_date' => 'date',
            'licence_end_date' => 'date',
            'returned_at' => 'datetime',
        ];
    }

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
     * @return string `created` | `updated` | `unchanged`
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

        $client = self::query()
            ->where(
                ($licenceNumber === null || $licenceNumber === '')
                    ? ['licence_propre_numero' => $licencePropre]
                    : ['licence_number' => $licenceNumber]
            )
            ->first();

        if ($client === null) {
            self::create(array_merge(
                ['categories' => []],
                $attributes,
                // État applicatif : toujours neuf à la création, quel que
                // soit ce que le payload aurait pu tenter d'envoyer.
                ['status' => self::STATUS_AVAILABLE, 'is_blacklisted' => false, 'returned_at' => null]
            ));

            return 'created';
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
     * @return array{received:int, processed:int, created:int, updated:int, unchanged:int, failed:int, errors:list<array{index:int, licence_number:?string, error:string}>}
     */
    public static function bulkUpsertFromScraperPayload(array $payloads): array
    {
        $result = [
            'received' => count($payloads),
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
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
                    $client = self::query()
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
     * forme normalisée (`Client::normalizePayloadKey()`), donc insensible à
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
     * (`Client::normalizePayloadKey()`) : `licence`, `licence_number`,
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
            return self::cleanText($item);
        }

        if (! is_array($item)) {
            return null;
        }

        foreach ($item as $key => $value) {
            if (! in_array(self::normalizePayloadKey((string) $key), self::BLACKLIST_LICENCE_KEYS, true)) {
                continue;
            }

            $licence = self::cleanText($value);

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
     * @return Collection<int, self>
     */
    private static function clientsMatchingLicence(string $licence): Collection
    {
        return self::query()
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
     * @return Collection<int, self>
     */
    private static function clientsMatchingName(string $name): Collection
    {
        return self::query()
            ->where(function (Builder $query) use ($name) {
                $searchTerm = '%' . strtolower($name) . '%';
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
     * @param  Collection<int, self>  $clients
     * @param  string  $label  nom ou licence visée, reprise dans la note
     * @return array{matched: int, blacklisted: int, already: int}
     */
    private static function applyBlacklist(Collection $clients, string $label): array
    {
        $matched = $clients->count();
        $blacklisted = 0;
        $already = 0;

        foreach ($clients as $client) {
            if ((bool) $client->is_blacklisted && $client->status === self::STATUS_BLACKLISTED) {
                $already += 1;

                continue;
            }

            $client->update([
                'is_blacklisted' => true,
                'status' => self::STATUS_BLACKLISTED,
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
            return self::cleanText($item);
        }

        if (! is_array($item)) {
            return null;
        }

        foreach ($item as $key => $value) {
            if (! in_array(self::normalizePayloadKey((string) $key), self::BLACKLIST_NAME_KEYS, true)) {
                continue;
            }

            $name = self::cleanText($value);

            if ($name !== null) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Clés d'item acceptées pour un numéro de téléphone — forme normalisée
     * (`Client::normalizePayloadKey()`), donc insensible à la casse et à la
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
     * `CallWorkflowService::NON_BLOCK_MONTHS`.
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

                $normalized = self::normalizePhone($phone);

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
        // workflow d'appel — CallWorkflowService::NON_BLOCK_MONTHS).
        $returnedAt = Carbon::now()->addMonths(CallWorkflowService::NON_BLOCK_MONTHS);
        $step['returned_at'] = $returnedAt->toDateTimeString();

        foreach ($clients as $client) {
            if ((bool) $client->is_blacklisted || $client->status === self::STATUS_BLACKLISTED) {
                // Une liste noire n'est jamais rétrogradée en simple
                // indisponibilité temporaire.
                $step['blacklisted'] += 1;

                continue;
            }

            if ($client->status === self::STATUS_UNAVAILABLE
                && $client->returned_at !== null
                && $client->returned_at->isFuture()) {
                $step['already'] += 1;

                continue;
            }

            $client->update([
                'status' => self::STATUS_UNAVAILABLE,
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
     * @return \Illuminate\Support\Collection<int, self>
     */
    private static function clientsByPhone(string $normalized)
    {
        $query = self::query()->whereNotNull('phone');
        $phone = $query->getQuery()->getGrammar()->wrap('phone');

        return $query
            ->whereRaw(self::digitsOnlySql($phone).' LIKE ?', ['%'.$normalized.'%'])
            ->get()
            ->filter(fn (self $client) => self::normalizePhone($client->phone) === $normalized)
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
            return self::cleanText($item);
        }

        if (! is_array($item)) {
            return null;
        }

        foreach ($item as $key => $value) {
            if (! in_array(self::normalizePayloadKey((string) $key), self::PHONE_ITEM_KEYS, true)) {
                continue;
            }

            $phone = self::cleanText($value);

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
        $status = self::cleanText($status) ?? '';
        $normalized = strtoupper($status);

        if (! in_array($normalized, self::STATUSES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Statut inconnu : « %s » (attendu : %s).',
                $status,
                implode(' / ', self::STATUSES)
            ));
        }

        $after = self::cleanText($after);
        $after = $after === null || $after === '' ? null : $after;

        $limit = max(1, (int) config(
            'public_api.no_reservations_status_limit',
            self::NO_RESERVATIONS_STATUS_LIMIT
        ));

        // On ne charge que les identifiants (une campagne « indisponibles »
        // peut viser plusieurs milliers de clients) et on plafonne :
        // `next_after` invite le script à reprendre au-delà.
        $query = self::query()->where('status', $normalized);

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
            ? self::query()->whereKey($key)->get()
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

            if ((bool) $client->is_blacklisted || $client->status === self::STATUS_BLACKLISTED) {
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
            $value = self::cleanText($item);

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
                $id = self::cleanText($value);

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
                $attributes[$column] = self::cleanText($attributes[$column]);
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
            $attributes['phone'] = self::cleanPhone($attributes['phone']);
        }

        if (array_key_exists('email', $attributes)) {
            $attributes['email'] = self::cleanEmail($attributes['email']);
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

    /** Trim + espaces répétés (dont insécables) réduits, quotes retirées. */
    private static function cleanText(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null; // NULL ou liste placée dans une colonne texte
        }

        $text = preg_replace('/[\x{00A0}\s]+/u', ' ', (string) $value) ?? '';
        $text = trim(trim($text), "\"'“”‘’«»");

        return $text === '' ? null : $text;
    }

    /** Texte + code de tête retiré + casse MAJUSCULE ramenée en casse normale. */
    private static function cleanLabel(mixed $value): ?string
    {
        $text = self::cleanText($value);

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
        $text = self::cleanText($value);

        if ($text === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $text) ?? '';

        return $digits !== '' ? $digits : $text;
    }

    /**
     * Téléphone nord-américain reformatté, extension conservée :
     * `5143535820 Ext.: 5417` → `514-353-5820 ext. 5417`.
     * Une valeur trop courte ou étrangère est laissée telle quelle.
     */
    private static function cleanPhone(mixed $value): ?string
    {
        $text = self::cleanText($value);

        if ($text === null) {
            return null;
        }

        $extension = null;
        if (preg_match('/^(.*?)\s*(?:ext(?:ension)?\.?|poste|#)\s*[:.]?\s*(\d{1,8})\s*$/iu', $text, $m)) {
            $text = trim($m[1]);
            $extension = $m[2];
        }

        $digits = preg_replace('/\D+/', '', $text) ?? '';

        if (strlen($digits) === 10) {
            $text = sprintf('%s-%s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
        } elseif (strlen($digits) === 11 && $digits[0] === '1') {
            $text = sprintf('1 %s-%s-%s', substr($digits, 1, 3), substr($digits, 4, 3), substr($digits, 7));
        }

        return $extension === null || $extension === ''
            ? ($text === '' ? null : $text)
            : sprintf('%s ext. %s', $text, $extension);
    }

    /**
     * Numéro de téléphone **détecté** : toutes les écritures d'un même
     * numéro sont ramenées à une clé unique de chiffres nationaux, si bien
     * que les formes ci-dessous sont reconnues comme un seul et même
     * numéro :
     *
     *   819-418-6550 · 8194186550 · (819) 418 6550 · 819.418.6550
     *   +1819-418-6550 · +18194186550 · +1-819-418-6550 · 1 819 418 6550
     *   → tous → `8194186550`
     *
     *  - l'**extension** est retirée (`819-418-6550 ext. 5417` → le numéro
     *    seul) : elle ne fait pas partie de l'identité du numéro ;
     *  - ponctuation, espaces (dont insécables) et `+` supprimés : chiffres
     *    seuls ;
     *  - l'indicatif nord-américain `1` (11 chiffres) retiré : la comparaison
     *    se fait sur le numéro **national**, la forme que stocke
     *    `cleanPhone()`.
     *
     * @return ?string clé de chiffres (`8194186550`), null si le champ ne
     *                 contient aucun chiffre (champ vide / illisible)
     */
    public static function normalizePhone(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null; // NULL ou liste placée dans un champ téléphone
        }

        $text = self::cleanText($value);

        if ($text === null) {
            return null;
        }

        // Extension postérieure (`… Ext.: 5417`, `… poste 3`) : non retenue.
        if (preg_match('/^(.*?)\s*(?:ext(?:ension)?\.?|poste|#)\s*[:.]?\s*(\d{1,8})\s*$/iu', $text, $m)) {
            $text = trim($m[1]);
        }

        $digits = preg_replace('/\D+/', '', $text) ?? '';

        if ($digits === '') {
            return null;
        }

        // Indicatif pays nord-américain : `+1 819…` et `819…` sont le même
        // numéro (les codes région NANP ne commencent jamais par 1).
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        return $digits;
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
            $candidate = trim($candidate, ".,;()<>");

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

    /**
     * Clients réellement disponibles : le statut AVAILABLE prime, et un
     * returned_at passé ne bloque plus (réactivation prise en charge même
     * si le cron horaire n'a pas encore tourné).
     */
    public function scopeAvailable($query)
    {
        return $query
            ->where('status', self::STATUS_AVAILABLE)
            ->where(fn ($q) => $q->whereNull('returned_at')->orWhere('returned_at', '<=', now()));
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function activeReservation()
    {
        return $this->hasOne(Reservation::class)->latest('created_at');
    }

    /**
     * Dernière réservation du client (colonne triée par date, puis id).
     * Préchargée par `Client::loadLatestReservations()` dans les listes.
     */
    public function latestReservation()
    {
        return $this->hasOne(Reservation::class, 'client_id')->latestOfMany('created_at', 'id');
    }

    /**
     * Réservation **courante** (pointeur dénormalisé, cf. migration
     * `2026_09_30_000002`) : la dernière réservation, jointure directe au
     * lieu d'une sous-requête. `current_reservation_id` en encode le statut ;
     * `current_comercial_id` porte l'employé qui la détient (visibilité du
     * téléphone, filtres « par commercial »).
     */
    public function currentReservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'current_reservation_id');
    }

    /** Employé détenteur de la réservation courante (NULL sans réservation). */
    public function currentComercial(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_comercial_id');
    }

    /**
     * Recalcule le pointeur « réservation courante » du client à partir de
     * `reservations`.
     *
     * **Seul chemin d'écriture** de `current_reservation_id` /
     * `current_comercial_id` : `Reservation` l'appelle à chaque `saved` et
     * `deleted` (création, issue d'appel, suppression), et
     * `AdminController::debloquerClient()` l'appelle après sa suppression
     * massique — la seule écriture qui contourne Eloquent. Le pointeur ne
     * peut donc pas diverger de la réalité.
     *
     * Écriture en query builder : aucun événement de modèle, et surtout pas
     * d'`updated_at` — une réservation qui évolue ne doit pas reclasser son
     * client dans les listes triées « mis à jour le ».
     */
    public function syncCurrentReservation(): void
    {
        // Même ordre que `latestReservation()` : created_at, puis id, décroissants.
        $latest = $this->reservations()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['id', 'client_id', 'comercial_id', 'status', 'created_at']);

        DB::table($this->getTable())
            ->where('id', $this->id)
            ->update([
                'current_reservation_id' => $latest?->id,
                'current_comercial_id' => $latest?->comercial_id,
            ]);

        // Miroir en mémoire : les écritures qui suivent dans la même requête
        // (payload, visibilité du téléphone) voient la bonne valeur sans
        // recharger la ligne, sans marquer le modèle comme modifié.
        $this->forceFill([
            'current_reservation_id' => $latest?->id,
            'current_comercial_id' => $latest?->comercial_id,
        ]);
        $this->syncOriginalAttributes(['current_reservation_id', 'current_comercial_id']);
        $this->setRelation('currentReservation', $latest);
    }

    // ------------------------------------------------------------------
    // Statut AFFICHÉ (jamais stocké, jamais utilisé pour filtrer).
    //
    // Un client réservé est qualifié par sa dernière réservation : le modèle
    // ne connaît qu'une seule valeur dérivée, `STATUS_IN_PROGRESS`
    // (BV_VOICEMAIL comme CALL_BACK affichent « En cours de traitement »).
    // ------------------------------------------------------------------

    /**
     * Statut « reformulé » d'un client, pour l'affichage dans les listes.
     *
     * Règle métier (docs/RULES.md §2) — un client encore **RESERVED** est
     * qualifié par l'état de sa **dernière réservation** :
     *
     *  - YES        -> CONFIRMED   « Confirmé »
     *  - NO         -> UNAVAILABLE « Non disponible » (+ retour)
     *  - BV_VOICEMAIL / CALL_BACK -> IN_PROGRESS « En cours de traitement »
     *  - sinon (PENDING, aucune)  -> son statut courant (« Réservé »)
     *
     * Tous les autres statuts (AVAILABLE, CONFIRMED, UNAVAILABLE,
     * BLACKLISTED…) sont retournés tels quels. `clients.status` brut n'est
     * **jamais modifié** : les filtres, les KPI et le workflow continuent de
     * le lire directement.
     *
     * 1 seule requête au maximum (si aucune réservation n'est préchargée) ;
     * passez par `loadLatestReservations()` pour une liste.
     */
    public function displayStatus(): string
    {
        if ($this->status !== self::STATUS_RESERVED) {
            return $this->status;
        }

        return match ($this->latestReservationStatus()) {
            Reservation::STATUS_YES => self::STATUS_CONFIRMED,
            Reservation::STATUS_NO => self::STATUS_UNAVAILABLE,
            Reservation::STATUS_BV_VOICEMAIL,
            Reservation::STATUS_CALL_BACK => self::STATUS_IN_PROGRESS,
            default => $this->status,
        };
    }

    /** Statut de la dernière réservation : relation préchargée, sinon requête unique. */
    private function latestReservationStatus(): ?string
    {
        if ($this->relationLoaded('latestReservation')) {
            return $this->latestReservation?->status;
        }

        if ($this->relationLoaded('reservations')) {
            return $this->reservations
                ->sortByDesc(fn (Reservation $r) => [$r->created_at, $r->id])
                ->first()?->status;
        }

        return $this->reservations()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('status');
    }

    /**
     * Précharge la dernière réservation de tous les clients « RESERVED » /
     * « CONFIRMED » / « UNAVAILABLE » d'un lot en **une seule requête** (sinon
     * `displayStatus()` ferait une requête par ligne à formatter, la visibilité
     * du téléphone une seconde, et la colonne « Statut » — qui affiche le
     * statut de la **dernière réservation** hors `AVAILABLE` / liste noire —
     * une troisième).
     *
     * @param  iterable<int|string, mixed>  $clients
     */
    public static function loadLatestReservations(iterable $clients): void
    {
        $pending = collect($clients)
            ->filter(fn ($client) => $client instanceof self
                && in_array($client->status, [self::STATUS_RESERVED, self::STATUS_CONFIRMED, self::STATUS_UNAVAILABLE], true)
                && ! $client->relationLoaded('latestReservation'));

        $ids = $pending->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }

        $latest = [];
        $rows = Reservation::query()
            ->whereIn('client_id', $ids)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            // `comercial_id` : permet de savoir qui détient la réservation
            // courante (visibilité du numéro de téléphone) sans requête de plus.
            ->get(['id', 'client_id', 'comercial_id', 'status', 'created_at']);

        foreach ($rows as $row) {
            // Déjà trié du plus récent au plus ancien : on garde le 1er vu.
            $latest[$row->client_id] ??= $row;
        }

        foreach ($pending as $client) {
            $client->setRelation('latestReservation', $latest[$client->id] ?? null);
        }
    }

    /**
     * Journal d'événements du client : issues d'appel (YES/NO/BV/CALL_BACK),
     * réservation (RESERVED), listes noires (BLACKLISTED / RETURNED_TO_AVAILABLE)
     * et commentaires libres (NOTE) — table unique `notes`.
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /** Rappels planifiés (table `rappels`, source de vérité des rappels). */
    public function rappels(): HasMany
    {
        return $this->hasMany(Rappel::class);
    }

    /**
     * Colonnes dont les valeurs distinctes sont exposées aux listes
     * déroulantes (`GET /categories`, `/municipalities`,
     * `/administrative-regions`). Liste blanche : le nom de colonne n'est
     * jamais repris d'une requête HTTP.
     */
    public const DISTINCT_COLUMNS = ['categories', 'municipality', 'administrative_region'];

    /** Cache des listes distinctes : 1 semaine (604 800 s). */
    public const DISTINCT_CACHE_TTL = 604800;

    /**
     * Valeurs distinctes d'une colonne, sans doublons, triées — alimente les
     * filtres de la liste des prospects. Lu via Eloquent : les casts du
     * modèle s'appliquent aux colonnes JSON, plus aucune requête DB::table().
     *
     * Résultat mis en cache une semaine ; il est invalidé à chaque
     * changement de clients (voir `forgetDistinctValues()`), donc les listes
     * se mettent à jour seules.
     *
     * @return list<string>
     */
    public static function distinctValues(string $column): array
    {
        if (! in_array($column, self::DISTINCT_COLUMNS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Colonne non exposée aux filtres : %s (attendu : %s)',
                $column,
                implode(', ', self::DISTINCT_COLUMNS)
            ));
        }

        return Cache::remember(
            self::distinctCacheKey($column),
            self::DISTINCT_CACHE_TTL,
            fn () => self::queryDistinctValues($column)
        );
    }

    /**
     * Invalide les listes distinctes mises en cache : appelée à chaque
     * création / mise à jour / suppression d'un client (événements du modèle)
     * et par `ClientsFromJsonSeeder` (suppressions en masse, qui ne
     * déclenchent pas ces événements). L'échéance hebdomadaire du cache ne
     * devient qu'un filet de sécurité.
     */
    public static function forgetDistinctValues(): void
    {
        foreach (self::DISTINCT_COLUMNS as $column) {
            Cache::forget(self::distinctCacheKey($column));
        }
    }

    /** Clé de cache d'une liste distincte. */
    private static function distinctCacheKey(string $column): string
    {
        return 'clients:distinct:'.$column;
    }

    /** Auto-invalidation du cache des listes distinctes. */
    protected static function booted(): void
    {
        static::saved(fn () => static::forgetDistinctValues());
        static::deleted(fn () => static::forgetDistinctValues());
    }

    /**
     * Requête réelle derrière `distinctValues()` (à ne pas appeler seule) :
     * `categories` est un tableau JSON éclaté en libellés, les autres colonnes
     * sont des chaînes (`SELECT DISTINCT`). Dans les deux cas, valeurs vides
     * écartées, doublons supprimés même à la casse près, puis tri.
     */
    private static function queryDistinctValues(string $column): array
    {
        $values = static::query()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column);

        return $values
            ->flatMap(function ($raw) use ($column) {
                // `categories` : le pluck Eloquent renvoie déjà des tableaux
                // décodés (cast `array`), on les éclate en libellés.
                if ($column === 'categories') {
                    $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

                    return is_array($decoded) ? $decoded : [$raw];
                }

                return [$raw];
            })
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            // Déduplication insensible à la casse : « Montréal » et
            // « montréal » ne doivent pas revenir en double.
            ->unique(fn ($value) => mb_strtolower(trim($value)))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Options des filtres des deux listes de prospects (GET /filters) : les
     * mêmes valeurs distinctes que GET /categories, GET /municipalities et
     * GET /administrative-regions, groupées en une seule réponse.
     *
     * Seule méthode du modèle qui n'est pas un scope : elle renvoie un
     * tableau d'agrégation, pas un Builder.
     *
     * @return array{categories: list<string>, municipalities: list<string>, administrative_regions: list<string>}
     */
    public static function getUniqueCategoriesAndMunicipalities(): array
    {
        return [
            'categories' => self::distinctValues('categories'),
            'municipalities' => self::distinctValues('municipality'),
            'administrative_regions' => self::distinctValues('administrative_region'),
        ];
    }

    /**
     * Filtre par municipalité (libellé exact), scope Eloquent chaînable :
     *   Client::query()->filterByMunicipalities(['Québec', 'Lévis'])
     *
     * Le filtre s'applique à la requête du modèle (casts, scopes globaux et
     * événements préservés) — plus de DB::table() ni de sous-requête
     * whereIn('id', …) qui doublonnait les requêtes.
     */
    public function scopeFilterByMunicipalities(Builder $query, string|array $municipalities): Builder
    {
        $values = self::nonEmptyValues($municipalities);

        return $values === [] ? $query : $query->whereIn('municipality', $values);
    }

    /**
     * Filtre par région administrative (libellé exact), scope Eloquent
     * chaînable, même mécanique que `filterByMunicipalities()` :
     *   Client::query()->filterByAdministrativeRegions('Lanaudière')
     */
    public function scopeFilterByAdministrativeRegions(Builder $query, string|array $regions): Builder
    {
        $values = self::nonEmptyValues($regions);

        return $values === [] ? $query : $query->whereIn('administrative_region', $values);
    }

    /**
     * Filtre par catégorie : contenance JSON (`categories` contient le
     * libellé), et non LIKE sur le JSON encodé. Plusieurs valeurs = OU.
     *
     * `whereJsonContains()` fait le travail côté moteur (JSON_CONTAINS sur
     * MySQL, json_each sur SQLite) : le libellé doit correspondre à un
     * élément exact du tableau — « Résidentiel » ne peut plus attraper un
     * libellé qui le contient par hasard.
     */
    public function scopeFilterByCategories(Builder $query, string|array $categories): Builder
    {
        $values = self::nonEmptyValues($categories);

        if ($values === []) {
            return $query;
        }

        return $query
            ->whereNotNull('categories')
            ->where('categories', '!=', '') // valeur non JSON : écartée avant la fonction
            ->where(function (Builder $nested) use ($values) {
                foreach ($values as $category) {
                    $nested->orWhereJsonContains('categories', $category);
                }
            });
    }

    /**
     * Filtre par **statut client** — un ou plusieurs statuts à la fois,
     * pour les badges « Tous + 4 statuts » de l'overview :
     *
     *   Client::query()->filterByStatuses('AVAILABLE,RESERVED')  // chaîne
     *   Client::query()->filterByStatuses(['AVAILABLE'])         // tableau
     *
     * Valeurs validées contre `Client::STATUSES` (une valeur inconnue est
     * ignorée), sans doublon ; vide / null = aucun filtre.
     *
     * Une ligne **blacklistée** appartient au seau `BLACKLISTED`, quel que
     * soit son `status` (une donnée réelle a encore `status = AVAILABLE`) :
     * elle n'entre donc que si `BLACKLISTED` est demandé. C'est la définition
     * **exacte** de `by_status` (`GET clients/overview`) — le chiffre affiché
     * sur un badge vaut alors le nombre de lignes renvoyées après clic.
     */
    public function scopeFilterByStatuses(Builder $query, string|array|null $statuses): Builder
    {
        $values = $statuses === null
            ? []
            : (is_array($statuses) ? $statuses : explode(',', (string) $statuses));

        $statuses = array_values(array_unique(array_intersect(
            array_map(fn ($value) => trim((string) $value), $values),
            self::STATUSES,
        )));

        if ($statuses === []) {
            return $query;
        }

        $others = array_values(array_diff($statuses, [self::STATUS_BLACKLISTED]));
        $hasBlacklist = in_array(self::STATUS_BLACKLISTED, $statuses, true);

        return $query->where(function (Builder $nested) use ($others, $hasBlacklist) {
            if ($others !== []) {
                $nested->where(function (Builder $group) use ($others) {
                    $group->whereIn('status', $others)
                        ->where('is_blacklisted', false);
                });
            }

            if ($hasBlacklist) {
                $others === []
                    ? $nested->where('is_blacklisted', true)
                    : $nested->orWhere('is_blacklisted', true);
            }
        });
    }

    /**
     * Filtre par **statut de réservation courante** (badges « Oui / Non /
     * BV / À rappeler / - » de la colonne « Statut », docs/RULES.md §9).
     *
     * Le statut affiché se déduit de `current_reservation_id` (pointer
     * maintenu par `syncCurrentReservation()`) : la valeur du badge et la
     * valeur filtrée proviennent donc de la **même** colonne — l'invariant
     * « compteur du badge = lignes rendues » tient par construction.
     *
     * Les deux dimensions de badges sont **disjoints** : un client `AVAILABLE`
     * (relisté) ou en liste noire affiche son statut **client**, jamais sa
     * réservation, et ce scope l'exclut donc toujours. Combiner
     * `filterByStatuses()` et `scopeFilterByReservationStatuses()` en `OR`
     * donne alors une union exacte.
     */
    public function scopeFilterByReservationStatuses(Builder $query, string|array|null $statuses): Builder
    {
        $values = $statuses === null
            ? []
            : (is_array($statuses) ? $statuses : explode(',', (string) $statuses));

        $statuses = array_values(array_unique(array_intersect(
            array_map(fn ($value) => trim((string) $value), $values),
            Reservation::STATUSES,
        )));

        if ($statuses === []) {
            return $query;
        }

        return $query
            ->where('is_blacklisted', false)
            ->where('status', '!=', self::STATUS_AVAILABLE)
            ->whereIn(
                'current_reservation_id',
                Reservation::query()->whereIn('status', $statuses)->select('id')
            );
    }

    /**
     * Recherche par sous-chaîne dans une colonne JSON (`respondents`,
     * `categories`, `authorized_categories`).
     *
     * `whereJsonContains()` exige une valeur exacte et `JSON_SEARCH()` est
     * exclusif à MySQL : LIKE sur la représentation textuelle reste la seule
     * option portable pour une recherche libre. C'est la seule expression SQL
     * brute du modèle, isolée dans ce scope.
     */
    public function scopeOrWhereJsonTextLike(Builder $query, string $column, string $like): Builder
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);

        return $query->orWhereRaw('CAST('.$wrapped.' AS CHAR) LIKE ?', [$like]);
    }

    /**
     * Recherche « **toutes colonnes** » (docs/RULES.md §8) — commune aux
     * listes de prospects : `GET /clients` (page Grande liste commerciale +
     * réservation d'un lot), `GET /commercials/clients` (Grande liste admin)
     * et l'historique d'un employé.
     *
     * **Téléphone indifféremment formaté** : la saisie est ramenée aux
     * chiffres seuls puis comparée à la colonne `phone` également privée de
     * sa ponctuation — `9500301807`, `(9500) 301-807`, `9900-0895-49` et
     * `+1 (9900) 0895-49` se retrouvent donc mutuellement, quel que soit le
     * côté qui est formaté (et même si les deux le sont, différemment).
     *
     * @param  string  $search  Terme saisi par l'utilisateur (tel quel).
     */
    public function scopeSearchAll(Builder $query, string $search): Builder
    {
        $like = '%'.$search.'%';
        $digits = preg_replace('/\D+/', '', $search);
        // `phone` re-mis en forme par `self::digitsOnlySql()` : REPLACE()
        // imbriqué et non REGEXP, seule écriture portable MySQL / SQLite
        // (les tests tournent sur SQLite in-memory — AGENTS.md §2).
        $phone = $query->getQuery()->getGrammar()->wrap('phone');

        return $query->where(function (Builder $q) use ($like, $digits, $phone) {
            $q->where('name', 'LIKE', $like)
                ->orWhere('enterprise_name', 'LIKE', $like)
                ->orWhere('email', 'LIKE', $like)
                ->orWhere('phone', 'LIKE', $like)
                ->orWhere('neq', 'LIKE', $like)
                ->orWhere('municipality', 'LIKE', $like)
                ->orWhere('licence_number', 'LIKE', $like)
                ->orWhere('licence_propre_numero', 'LIKE', $like)
              // Colonnes JSON : sous-chaîne via le scope dédié
              // (voir Client::scopeOrWhereJsonTextLike).
                ->orWhereJsonTextLike('respondents', $like)
                ->orWhereJsonTextLike('categories', $like)
                ->orWhereJsonTextLike('authorized_categories', $like);

            if ($digits !== '') {
                $q->orWhereRaw(self::digitsOnlySql($phone).' LIKE ?', ['%'.$digits.'%']);
            }
        });
    }

    /**
     * Expression SQL « chiffres seuls » d'une expression de colonne :
     * retire espaces (dont insécable), tirets, parenthèses, points, `+` et
     * `/` — la ponctuation usuelle des numéros de téléphone.
     */
    private static function digitsOnlySql(string $expr): string
    {
        $separators = [' ', "\xC2\xA0", '-', '(', ')', '.', '+', '/'];

        foreach ($separators as $separator) {
            $expr = 'REPLACE('.$expr.", '".$separator."', '')";
        }

        return $expr;
    }

    /**
     * Colonnes triables de la page « Prospects » (`GET /clients`) — la même
     * liste sert à la réservation d'un lot (`POST clients/reserver`).
     */
    public const SORTABLE = [
        'name' => 'name',
        'enterprise_name' => 'enterprise_name',
        'email' => 'email',
        'phone' => 'phone',
        'status' => 'status',
        'municipality' => 'municipality',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
        'licence_end_date' => 'licence_end_date',
    ];

    /**
     * Requête **partagée** de la page « Prospects » : filtres (scopes
     * Eloquent), exclusions d'affichage et tri.
     *
     * `GET /clients` **et** `POST clients/reserver` passent par ce scope :
     * le lot réservé est exactement celui que l'employé voit à l'écran, dans
     * le même ordre (docs/RULES.md §7 — « même filtre et même tri que la
     * page »). `page` / `per_page` sont ignorés : la réservation prend le
     * premier lot de candidats de cette liste.
     *
     * @param  array<string, mixed>  $input  Paramètres de requête de la page.
     */
    public function scopeProspectList(Builder $query, array $input = []): Builder
    {
        if ($municipality = $input['municipality'] ?? null) {
            $query->filterByMunicipalities($municipality);
        }

        if ($category = $input['category'] ?? null) {
            $query->filterByCategories($category);
        }

        if ($region = $input['administrative_region'] ?? null) {
            $query->filterByAdministrativeRegions($region);
        }

        if ($search = $input['search'] ?? null) {
            // Recherche « toutes colonnes » + téléphone indifféremment
            // formaté — même scope que la Grande liste admin (§8).
            $query->searchAll($search);
        }

        // Exclusions de la page : hors liste noire, réellement disponible,
        // aucune réservation active en cours (ni la sienne ni celle d'un
        // autre employé).
        $query->where('is_blacklisted', false)
            ->available()
            ->whereDoesntHave('reservations', fn ($q) => $q->active());

        // Même tri que la page (défaut : `created_at` desc).
        $sortBy = (string) ($input['sort_by'] ?? 'created_at');
        $sortOrder = strtolower((string) ($input['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        if (array_key_exists($sortBy, self::SORTABLE)) {
            $query->orderBy(self::SORTABLE[$sortBy], $sortOrder);
        } else {
            $query->orderByDesc('created_at');
        }

        return $query;
    }

    /** Liste de filtre nettoyée : chaînes non vides uniquement. */
    private static function nonEmptyValues(string|array $values): array
    {
        return array_values(array_filter(
            (array) $values,
            fn ($value) => is_string($value) && trim($value) !== ''
        ));
    }
}
