To implement a public API endpoint that allows bulk insertion/upsetting of clients (e.g., from scraped payload lists) with CORS enabled specifically for that route, follow these actionable tasks:
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class Client extends Model
{
    public const STATUS_AVAILABLE = 'available';

    protected $guarded = [];

    protected $casts = [
        'respondents' => 'array',
        'authorized_categories' => 'array',
        'licence_start_date' => 'date',
        'licence_end_date' => 'date',
        'surety_amount' => 'float',
        'respondent_count' => 'integer',
        'sub_category_count' => 'integer',
        'licence_propre_numero' => 'integer',
    ];

    /**
     * Payload JSON / Scraper key map -> database column names.
     */
    public const PAYLOAD_MAP = [
        '' => 'licence_number',
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
     * Entry point to normalize and upsert a client record from scraper payload.
     */
    public static function upsertFromScraperPayload(array $payload): self
    {
        $attributes = self::attributesFromPayload($payload);

        if (empty($attributes['licence_number'])) {
            throw new \InvalidArgumentException('Licence number is required for client upsert.');
        }

        $attributes['status'] ??= self::STATUS_AVAILABLE;

        return DB::transaction(function () use ($attributes) {
            $client = self::updateOrCreate(
                ['licence_number' => $attributes['licence_number']],
                $attributes
            );

            // Invalidate distinct value filter caches if applicable
            if (method_exists(self::class, 'forgetDistinctValues')) {
                self::forgetDistinctValues();
            }

            return $client;
        });
    }

    /**
     * Maps raw incoming payload keys and normalizes all fields before database entry.
     */
    public static function attributesFromPayload(array $payload): array
    {
        $attributes = [];

        foreach (self::PAYLOAD_MAP as $payloadKey => $dbColumn) {
            if (array_key_exists($payloadKey, $payload)) {
                $attributes[$dbColumn] = self::castPayloadValue($dbColumn, $payload[$payloadKey]);
            }
        }

        // Fallback for licence_number if mapped via standard column name in payload
        if (empty($attributes['licence_number']) && !empty($payload['licence_number'])) {
            $attributes['licence_number'] = trim((string) $payload['licence_number']);
        }

        return $attributes;
    }

    /**
     * Normalizes and typecasts individual column values.
     */
    private static function castPayloadValue(string $column, mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value, " \t\n\r\0\x0B'\"");
        }

        if ($value === null || $value === '') {
            return null;
        }

        return match ($column) {
            'licence_propre_numero', 'respondent_count', 'sub_category_count' => (int) $value,
            'surety_amount' => is_numeric($value) ? (float) $value : null,
            'phone', 'neq' => (string) $value,
            'licence_start_date', 'licence_end_date' => self::payloadDate($value),
            'respondents', 'authorized_categories' => self::normalizeAndParseArray($value),
            default => is_scalar($value) ? (string) $value : null,
        };
    }

    /**
     * Handles string array normalization: splits pipe-delimited strings, converts 
     * single strings to arrays, cleans trailing whitespace, and removes duplicate entries.
     */
    private static function normalizeAndParseArray(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } elseif (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $items = $decoded;
            } else {
                // Handle pipe-delimited strings if provided in legacy string format
                $items = explode('|', $value);
            }
        } else {
            return [];
        }

        $cleaned = [];
        foreach ($items as $item) {
            if (!is_string($item)) {
                continue;
            }

            $trimmed = trim($item, " \t\n\r\0\x0B'\"");

            // Strip leading count prefixes if present (e.g. "1 ", "1.2 ", "23 ")
            $normalized = preg_replace('/^\d+(\.\d+)?\s+/u', '', $trimmed);

            if ($normalized !== null && $normalized !== '') {
                $cleaned[] = $normalized;
            }
        }

        return array_values(array_unique($cleaned));
    }

    /**
     * Safely converts ISO 8601 strings or dates to Y-m-d format for database storage.
     */
    private static function payloadDate(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
```markdown
# Public Client Bulk Upsert API Setup

## 1. CORS Configuration
- [ ] Open `config/cors.php`.
- [ ] Add the public route path (e.g., `api/v1/clients/bulk-upsert`) to the `paths` array.
- [ ] Set `allowed_origins` to `['*']` (or specify target origins) and ensure `allowed_methods` includes `POST` and `OPTIONS`.

## 2. API Controller & Route Definition
- [ ] Create a controller for handling client API requests:
  ```bash
  php artisan make:controller Api/ClientApiController

```

* [ ] Add a `bulkUpsert` method to `ClientApiController` to handle bulk requests:
* Validate that incoming requests contain an array of client records.
* Wrap processing in a transaction or loop through records using `Client::upsertFromScraperPayload()`.
* Return a structured JSON response with status, count of processed items, and error reporting for failed entries.


* [ ] Register the route in `routes/api.php` without authentication middleware:
```php
Route::post('/v1/clients/bulk-upsert', [ClientApiController::class, 'bulkUpsert']);

```



## 3. Model Enhancements

* [ ] Add a `bulkUpsertFromScraperPayload(array $payloads)` method to `App\Models\Client` to process multiple items inside a single database transaction.
* [ ] Ensure validation errors in individual items are captured gracefully without breaking the entire payload process (or roll back based on requirements).

## 4. Testing & Verification

* [ ] Test the endpoint with `curl` or Postman using payload samples (e.g., array of JSON scraper records).
* [ ] Verify CORS preflight requests (`OPTIONS` method) respond with proper `Access-Control-Allow-Origin` headers.

```

```

---

# Public Client Bulk Delete API

Bulk deletion of scraped clients on the **same public surface** as the
upsert endpoint (no authentication, CORS via `config/cors.php` → `api/*`,
lot size bounded by `PUBLIC_API_MAX_ITEMS`, default 1000).

## 1. Route

```php
// routes/api/shared.php — same action behind POST and DELETE
Route::match(['post', 'delete'], 'clients/bulk-delete', [PublicClientController::class, 'bulkDelete']);
```

* `POST  /api/v1/clients/bulk-delete`
* `DELETE /api/v1/clients/bulk-delete`

## 2. Request bodies (all accepted)

```jsonc
{"licences": ["L-1001", "L-1002"]}
{"clients": [{"Licence": "L-1001"}, {"Licence (propre)": 4001}]}
["L-1001", "L-1002"]           // bare JSON list of licence numbers
```

* string item → matched on `licence_number`;
* object item → same French payload keys as the upsert (`Licence`, …) with
  the `Licence (propre)` fallback;
* invalid envelope, empty list or over-sized lot → **422**, nothing deleted.

## 3. Guard rule — linked data is never deleted

`reservations`, `notes` and `rappels` are all `cascadeOnDelete()`: deleting
such a client would wipe its history. For each item the endpoint therefore:

1. locks the client row **in its own transaction** and counts its links;
2. **if it has any linked row → the delete is ignored** (`skipped`, with
   the per-table counts) and **the loop moves on to the next client**;
3. if it is childless → the client is deleted (Eloquent delete, so the
   distinct-filter cache is invalidated);
4. if the licence does not exist → `missing`.

## 4. Response

```jsonc
{
  "success": true,
  "data": {
    "received": 3, "processed": 3,
    "deleted": 1, "skipped": 1, "missing": 1, "failed": 0,
    "skipped_items": [
      {"index": 1, "licence_number": "L-1001",
       "linked": {"reservations": 2, "notes": 5, "rappels": 1}}
    ],
    "missing_items": [{"index": 2, "licence_number": "L-4040"}],
    "errors": []
  }
}
```

`success` is `false` only when **no** item could be examined; details are
always in `skipped_items` / `missing_items` / `errors`.

## 5. Tests

`backend/tests/Feature/PublicClientBulkDeleteTest.php` (10 tests): guest
access, `DELETE` verb, skip + continue, note-only client, missing licence,
string/object/bare-list bodies, 422 envelopes, lot bound, filter-cache
invalidation, CORS preflight.

---

# Public Convert-To-Blacklist API

Third public route on the same surface (no authentication, CORS `api/*`,
lot bounded by `PUBLIC_API_MAX_ITEMS`):

* `POST /api/v1/clients/convert-to-blacklist` — blacklist clients **by name
  or by licence** (the envelope picks the mode: `{"name(s)": …}` → name,
  `{"licence(s)": …}` → licence, `{"clients": […]}` / bare JSON list →
  auto).
  * name → `LOWER(enterprise_name) LIKE '%name%' OR LOWER(name) LIKE
    '%name%'` — **partial** (substring) and case-insensitive, every matching
    row converted (historical behaviour of the name mode) ;
  * licence → **exact equality**: numeric value hits `licence_propre_numero`
    **and** `licence_number`, textual value (`5747-5089-01`) hits
    `licence_number` only (no `RB-…` false positive).

**Endpoint is temporary** — full spec (request bodies, licence-search rules,
business gesture §3.4, consolidated report, removal procedure): [`docs/convert_to_blacklist_api.md`](convert_to_blacklist_api.md).
Report keys: `processed` (success) / `zapped` / `ignored` (unknown target =
ignored, never an error) / `failed` (errors) — every report row carries
`type` (`name` / `licence`), `key` and `name` **or** `licence` — rerun-safe
(idempotent).
Tests: `backend/tests/Feature/PublicClientConvertToBlacklistTest.php` (23 tests).
Campaign script: `../blacklist/upload_blacklist_licences.py` (500 per batch).

---

# Public Convert-To-Unavailable API

Fourth public route on the same surface (no authentication, CORS `api/*`,
lot bounded by `PUBLIC_API_MAX_ITEMS`):

* `POST /api/v1/clients/convert-to-unavailable` — mark clients
  **`UNAVAILABLE` by phone number**, `returned_at = now + 3 months` (the
  "NO" call outcome, `CallWorkflowService::NON_BLOCK_MONTHS`).

**Phone number is detected in any format on both sides**
(`Client::normalizePhone()`): `819-418-6550`, `8194186550`,
`(819) 418 6550`, `+1819-418-6550`, `+18194186550`, `+1-819-418-6550`,
`1 819 418 6550`, `819-418-6550 ext. 5417` all collapse to the same
10-digit key; every matching row is blocked (SQL digits-only pre-filter +
exact PHP check, portable MySQL / SQLite).

**Endpoint is temporary** — full spec (request bodies, phone-format
table, business gesture §3, success **and** failure responses, removal
procedure): [`docs/convert_to_unavailable_api.md`](convert_to_unavailable_api.md).
Report keys: `processed` (success) / `blocked` (rows written) / `ignored`
(unknown number = `not_found`, never an error; already blocked;
blacklisted rows are never demoted) / `failed` (errors) — rerun-safe
(idempotent). `422` only for an invalid body or an oversized lot; `200`
with `success: false` when every item failed.
Tests: `backend/tests/Feature/PublicClientConvertToUnavailableTest.php` (15 tests)
+ `backend/tests/Feature/ClientBulkUnavailableFromPhoneTest.php` (11 model tests).

---

# Public Create-NO-Reservations API

Fifth public route on the same surface (no authentication, CORS `api/*`,
lot bounded by `PUBLIC_API_MAX_ITEMS`; the `{"status": …}` perimeter mode is
bounded by `PUBLIC_API_NO_RESERVATIONS_STATUS_LIMIT`, default 20 000, and
paginated with the `{"after": "<uuid>"}` cursor + `next_after` in the
response):

* `POST /api/v1/clients/create-no-reservations` — write **one
  `reservations` row with `status = NO`** per targeted client, attributed to a
  **single employee** (`public_api.no_reservations_comercial_email`,
  resolved by email — the stock is *not* spread over all commercials).
  `reservation_group_id` stays `NULL`: no employee list appears in
  « Mes listes ».
* Targets: `{"status": "UNAVAILABLE"}` (whole perimeter, cursor-paginated),
  `{"licence": …}` / `{"licences": […]}` (same licence resolution as
  `convert-to-blacklist`), `{"client_id": …}` / `{"client_ids": […]}` (uuid),
  `{"clients": […]}` or a bare JSON list (uuid **or** licence).

**This is preparation data, not the call workflow**: it writes the
reservation row and **nothing else** — no `NO` note, no `returned_at`
(+3 months), no client status change, no auto-blacklist (§3.4 case 6). The
real gesture is still produced exclusively by `CallWorkflowService::apply()`.
The client status stays strictly unchanged.

**Endpoint is temporary** — full spec (request bodies, what is / is not
written, ignored reasons, consolidated report, removal procedure **and what
to do about rows already written**): [`docs/create_no_reservations_api.md`](create_no_reservations_api.md).
Report keys: `processed` (success) / `created` (reservations written) /
`already` (`already_no`) / `skipped` (`blacklisted`) / `not_found` / `failed`
— rerun-safe (idempotent): at most one `NO` reservation per (client,
employee). `422` for an invalid body, an unknown status, a body mixing
`status` and a list, or an oversized lot.
Tests: `backend/tests/Feature/PublicClientNoReservationsTest.php` (16 tests).
Campaign scripts: `../blacklist/upload_no_reservations.py` +
`../blacklist/generer_rapport_no_reservations.py`.