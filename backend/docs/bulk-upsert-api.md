# Bulk Upsert Clients API

Public M2M webhook to create or update prospect clients in bulk from an external scraper / n8n flow.

| | |
|---|---|
| **Method** | `POST` |
| **Path** | `/api/v1/clients/bulk-upsert` |
| **Auth** | Shared API key header `X-Api-Key` (no user login / no Sanctum) |
| **Content-Type** | `application/json` |

- Sources: controller `PublicClientController::bulkUpsert()`, route `backend/routes/api/shared.php`, service `ClientImportService::bulkUpsertFromScraperPayload()`, guard `VerifyExternalSystemKey`.

## Authentication

Every request must carry the `X-Api-Key` header, compared server-side against `EXTERNAL_SYSTEM_API_KEY` in constant time.

- Missing / wrong key → `401`
- Server key not configured → `401` (fail-closed: the endpoint is always locked unless a key is set)

```
X-Api-Key: <your-shared-secret>
```

## Query Parameters

| Param | Type | Required | Default | Description |
|---|---|---|---|---|
| `source` | string | No | `Affaire` | Prospect origin assigned to items that do not carry their own `Source` field. |

## Request Body

Two accepted shapes, both a batch of client objects:

**1. Envelope form**

```json
{
  "clients": [
    { "Licence": "1100-3571-01", "Nom de l'intervenant / Entreprise": "ACME Inc." }
  ]
}
```

**2. Raw JSON array**

```json
[
  { "Licence": "1100-3571-01" },
  { "Licence": "RB-5678" }
]
```

### Item fields

Field keys use the French display labels from the payload; they map to snake_case columns and are matched case- and punctuation-insensitively. Unknown keys (and protected application state) are ignored.

| Payload key | Column | Notes |
|---|---|---|
| `Licence` | `licence_number` | Primary upsert key |
| `Licence (propre)` | `licence_propre_numero` | Fallback upsert key (unique) |
| `Nom de l'intervenant / Entreprise` | `enterprise_name` | Also fills `name` / `intervenant_name` on create |
| `Statut de la licence` | `licence_status` | |
| `NEQ` | `neq` | |
| `Adresse complète` | `full_address` | |
| `Municipalité` | `municipality` | |
| `Région administrative` | `administrative_region` | |
| `Téléphone` | `phone` | |
| `Courriel` | `email` | |
| `Nombre de répondants` | `respondent_count` | |
| `Répondants / Interlocuteurs (Qualifications)` | `respondents` | |
| `Nombre de sous-catégories` | `sub_category_count` | |
| `Catégories et sous-catégories autorisées` | `authorized_categories` | Derives `categories` |
| `Cautionnement (Compagnie / Association)` | `surety_company` | |
| `Montant de la caution ($)` | `surety_amount` | |
| `Date de début / délivrance` | `licence_start_date` | |
| `Date de fin / paiement annuel` | `licence_end_date` | |
| `Source` | `source` | Overrides the `source` query param for this item |

## Upsert Rules

- **Key resolution:** match by `licence_number`; if absent, fall back to `licence_propre_numero`.
- **Missing key:** an item with neither `Licence` nor `Licence (propre)` is rejected as `failed`.
- **New clients** are created with application state forced to `status = AVAILABLE`, `is_blacklisted = false`, `returned_at = null`.
- **Protected state never written:** `status`, `is_blacklisted`, `returned_at` and reservation fields are excluded from the payload mapping, even if sent.
- **Unchanged rows** are not rewritten, so `updated_at` stays stable across re-syncs.
- **Manually updated rows** (`is_manually_updated = true`) are left untouched and counted in `skipped_manual`.
- **Batch limit:** max `PUBLIC_API_MAX_ITEMS` records per call (default `1000`); larger payloads return `422`.

## Response

### 200 OK

Returned even on partial success. `success` is `false` only when **no** row could be processed; individual problems appear in `failed` / `errors`.

```json
{
  "success": true,
  "data": {
    "received": 3,
    "processed": 2,
    "created": 1,
    "updated": 1,
    "unchanged": 0,
    "skipped_manual": 0,
    "failed": 1,
    "errors": [
      {
        "index": 2,
        "licence_number": null,
        "error": "Clé d'upsert manquante : « Licence » (ou « Licence (propre) » en repli)."
      }
    ]
  }
}
```

| Field | Description |
|---|---|
| `received` | Number of items in the request |
| `processed` | Items successfully handled (created/updated/unchanged/skipped_manual) |
| `created` | New clients inserted |
| `updated` | Existing clients changed |
| `unchanged` | Identical rows, not rewritten |
| `skipped_manual` | Rows protected by `is_manually_updated` |
| `failed` | Items rejected |
| `errors[]` | Per-item report: `index`, `licence_number`, `error` |

### 401 Unauthorized

```json
{ "success": false, "message": "En-tête X-Api-Key manquant ou invalide." }
```

### 422 Unprocessable Entity

Invalid body shape, empty array, or oversized batch.

```json
{ "success": false, "error": "Format attendu : {\"clients\": [...]} ou une liste JSON." }
```

## Example

```bash
curl -X POST 'https://<host>/api/v1/clients/bulk-upsert?source=Scraper' \
  -H 'X-Api-Key: <your-shared-secret>' \
  -H 'Content-Type: application/json' \
  -d '{
    "clients": [
      {
        "Licence": "1100-3571-01",
        "Nom de l'intervenant / Entreprise": "ACME Inc.",
        "Municipalité": "Québec",
        "Téléphone": "418-555-1212"
      }
    ]
  }'
```
