# API publique — Indisponibilité en masse par numéro de téléphone

> ⚠️ **Endpoint public temporaire** — aucune authentification, appelable par
> n'importe qui depuis le navigateur (CORS ouvert sur `api/*`). Il est prévu
> pour être **retiré** : procédure de suppression en §7.

Même surface publique que `clients/bulk-upsert`, `clients/bulk-delete` et
`clients/convert-to-blacklist` (spec : `docs/public_api.md`,
`docs/convert_to_blacklist_api.md`, règles métier : `docs/RULES.md` §12) :
pas de `auth:sanctum`, CORS via `config/cors.php`, lot borné par
`PUBLIC_API_MAX_ITEMS` (défaut **1000**, `config/public_api.php`).

**Objet** : passer un ou plusieurs clients en **indisponible** à partir de
leur numéro de téléphone, avec retour automatique **3 mois plus tard** —
le geste métier du « NON » (RULES §3), rejouable en masse depuis une liste
de numéros.

## 1. Route

```php
// backend/routes/api/shared.php — déclarée avant toute route `clients/{…}`
Route::post('clients/convert-to-unavailable', [PublicClientController::class, 'convertToUnavailable']);
```

| Élément | Fichier |
|---|---|
| Route | `backend/routes/api/shared.php` |
| Contrôleur | `backend/app/Http/Controllers/Api/V1/Shared/PublicClientController.php` → `convertToUnavailable()` / `phoneItems()` |
| Modèle | `backend/app/Models/Client.php` → `bulkUnavailableFromPhone()` / `unavailableByPhone()` / `clientsByPhone()` |
| Détection | `backend/app/Models/Client.php` → `normalizePhone()` |
| CORS | `backend/config/cors.php` → `paths[]` (trace de la route ouverte) |
| Tests | `backend/tests/Feature/PublicClientConvertToUnavailableTest.php` (15 tests) + `backend/tests/Feature/ClientBulkUnavailableFromPhoneTest.php` (11 tests modèle) |

```
POST /api/v1/clients/convert-to-unavailable
```

## 2. Corps de requête (4 formes acceptées)

```jsonc
{"phone": "819-418-6550"}                                  // 1 numéro
{"phones": ["819-418-6550", "+1-418-555-1212"]}            // lot de numéros
{"clients": [{"phone": "819-418-6550"}]}                   // enveloppe commune
["819-418-6550", "418-555-1212"]                           // liste JSON nue
```

* **objet item** → première clé de téléphone reconnue (forme normalisée :
  casse et ponctuation ignorées) : `phone`, `telephone` / `Téléphone`,
  `tel`, `cell` / `cellulaire`, `numero` / `numéro` ;
* **chaîne item** → le numéro lui-même ;
* corps non JSON, enveloppe inconnue, tableau vide, `phone` vide ou non
  chaîne, lot trop long → **`422`** (rien n'est modifié) ;
* un item **présent mais illisible** (`"abc"`, sans aucun chiffre) n'est
  **pas** un 422 : il est compté dans `failed` au sein du rapport, le reste
  du lot est traité (voir §5).

## 3. Détection du numéro — un numéro, tous les formats

C'est le cœur de l'API : **la forme du numéro n'a aucune importance**, des
deux côtés (numéro saisi dans la requête **et** colonne `phone` stockée en
base). Toutes les écritures ci-dessous désignent un **seul et même
numéro** et retombent sur la clé `8194186550` :

| Saisie acceptée | Clé détectée |
|---|---|
| `819-418-6550` | `8194186550` |
| `8194186550` | `8194186550` |
| `(819) 418 6550` | `8194186550` |
| `819.418.6550` | `8194186550` |
| `819 418 6550` | `8194186550` |
| `+1819-418-6550` | `8194186550` |
| `+18194186550` | `8194186550` |
| `+1-819-418-6550` | `8194186550` |
| `1 819 418 6550` | `8194186550` |
| `819-418-6550 ext. 5417` (extension retirée) | `8194186550` |
| `""`, `"sans numéro"`, `null` | **aucune clé** → `failed` |

Règles de `Client::normalizePhone()` :

1. **extension retirée** (`… Ext.: 5417`, `… poste 3`) : elle ne fait pas
   partie de l'identité du numéro ;
2. **ponctuation, espaces (dont insécables) et `+` supprimés** : chiffres
   seuls ;
3. **indicatif nord-américain `1` retiré** (11 chiffres) : la comparaison
   se fait sur le numéro **national** — les codes région NANP ne
   commencent jamais par 1.

**Recherche en base** (portable MySQL / SQLite) :

1. pré-filtre SQL sur `phone` privée de sa ponctuation (`REPLACE` imbriqués,
   `LIKE '%8194186550%'`) — on ne charge jamais la table entière ;
2. vérification **exacte** en PHP par `normalizePhone()` sur chaque
   candidat (extension, indicatif 1, formes non couvertes par les
   `REPLACE`).

Conséquences :

* un même numéro peut viser **plusieurs lignes** (doublons en base) :
  **toutes** sont traitées, aucune notion de « première occurrence » ;
* **aucune correspondance → ignoré** : `not_found` (donc `ignored`), **ce
  n'est pas une erreur** et le lot continue avec le numéro suivant ;
* lot **ré-exécutable** (idempotent) : un second passage rend
  `blocked = 0` et `ignored = received`, toujours sans erreur.

## 4. Geste métier appliqué (RULES §3 — issue « NON »)

Pour **chaque ligne** trouvée, dans **une transaction par numéro** :

| Avant | Après |
|---|---|
| `status` | `UNAVAILABLE` |
| `returned_at` | `now + 3 mois` (`CallWorkflowService::NON_BLOCK_MONTHS`) |
| `is_blacklisted` | **inchangé** (jamais rétrogradé, cf. ci-dessous) |
| `rappels` du client | **supprimés** |
| `reservations` / `notes` | **conservés** (l'indisponibilité ne supprime pas l'historique) |
| journal | note `NOTE`, émetteur **`SYSTEM`** (l'API n'a pas d'utilisateur) |

* **retour automatique** : le cron `clients:reactivate`
  (`ProcessClientReactivation`) remet les clients `UNAVAILABLE` dont
  `returned_at` est passé en `AVAILABLE` — le blocage se lève donc tout
  seul au bout de 3 mois ;
* **rappels annulés** : sans cela, le cron des rappels expirés
  ré-appliquerait son propre blocage (21 jours) et écraserait le retour à
  3 mois ;
* une ligne **déjà `UNAVAILABLE`** avec un `returned_at` futur n'est **ni
  réécrite ni re-journalisée** : elle compte dans `already_unavailable`,
  son `updated_at` ne bouge pas ;
* une ligne **en liste noire n'est jamais rétrogradée** en simple
  indisponibilité temporaire : elle compte dans `blacklisted`
  (`is_blacklisted = true` **ou** `status = BLACKLISTED`), son
  `returned_at` reste `NULL` ;
* l'invalidation des listes distinctes (`Client::forgetDistinctValues()`)
  est déclenchée par l'événement `saved` du modèle, comme ailleurs ;
* **aucun état applicatif n'est lu du corps** : la requête ne porte que des
  numéros (pas de `status`, pas de `returned_at` à imposer).

## 5. Réponse — rapport consolidé

Toujours **`200`** dès qu'au moins un numéro a pu être examiné ; **`422`**
uniquement pour un corps invalide / un lot trop long (§2).

```jsonc
{
  "success": true,
  "data": {
    "received": 6,                 // numéros reçus
    "processed": 5,                // ✅ SUCCÈS : numéros traités sans erreur
    "matched": 5,                  // lignes clients trouvées (toutes formes)
    "blocked": 3,                  // ⛔ lignes réellement passées en UNAVAILABLE 3 mois
    "ignored": 3,                  // 🚫 numéros sans effet (introuvable + sans effet)
    "not_found": 1,                //   dont introuvables        → reason "not_found"
    "already_unavailable": 1,      //   dont lignes déjà bloquées (compteur de lignes)
    "blacklisted": 1,              //   dont lignes liste noire  (compteur de lignes)
    "failed": 1,                   // ❌ ERREURS : numéros/items en échec

    "blocked_items": [
      {"index": 0, "phone": "819-418-6550", "matched": 2, "blocked": 2,
       "returned_at": "2027-01-02 14:03:11"}
    ],
    "ignored_items": [
      {"index": 1, "phone": "514-555-0000",     "reason": "not_found"},
      {"index": 2, "phone": "418-555-1212",     "reason": "blacklisted"},
      {"index": 3, "phone": "819-418-6550",     "reason": "already_unavailable"}
    ],
    "errors": [
      {"index": 4, "phone": null, "error": "Numéro manquant : « phone » (ou item fourni en chaîne nue)."},
      {"index": 5, "phone": "abc", "error": "Numéro illisible : aucun chiffre détecté (ex. « 819-418-6550 »)."}
    ]
  }
}
```

| Compteur | Unité | Signification |
|---|---|---|
| `processed` | numéros | **succès** = `received − failed` |
| `matched` | lignes clients | lignes trouvées par les numéros du lot |
| `blocked` | lignes clients | lignes **réellement** passées en `UNAVAILABLE` +3 mois |
| `ignored` | numéros | sans effet : `not_found` + trouvés mais non bloqués |
| `not_found` | numéros | aucun client ne porte le numéro → `reason: "not_found"` |
| `already_unavailable` | lignes clients | déjà indisponibles, non réécrites |
| `blacklisted` | lignes clients | en liste noire, jamais rétrogradées |
| `failed` | numéros/items | **erreurs** → détaillées dans `errors[]` |

### Succès

* `success = true` **dès qu'un seul numéro a pu être examiné**
  (`processed > 0`) — même si rien n'a été bloqué (tout introuvable ou tout
  déjà fait) : ce n'est pas une erreur ;
* `blocked_items[]` détaille, par numéro traité, le nombre de lignes
  trouvées / bloquées et le `returned_at` écrit (commun à toutes les
  lignes du numéro : `now + 3 mois`) ;
* `received = processed + failed` ; les 4 compteurs (`processed`,
  `blocked`, `ignored`, `failed`) répondent à la question « combien de
  succès / bloqués / ignorés / erreurs » d'un **seul coup d'œil** ;
* **ré-exécutable** : un 2e passage sur les mêmes numéros donne
  `blocked = 0`, `ignored = received`, `failed = 0`.

### Échecs

Trois niveaux, du plus grave au plus bénin :

| Niveau | Code | Quand | Effet |
|---|---|---|---|
| **Rejet de lot** | `422` + `{"success": false, "error": "…"}` | corps non JSON, enveloppe inconnue, tableau vide, `phone` vide/non chaîne, lot > `PUBLIC_API_MAX_ITEMS` | **rien** n'est modifié |
| **Item en échec** | `200` + `success: false` (si tout échoue) | item sans clé de téléphone (`{"municipality": …}`) ou numéro sans aucun chiffre (`"abc"`) | cet item compte dans `failed` + `errors[]` ; **le reste du lot est traité** |
| **Numéro introuvable** | `200` + compteur `not_found` | aucun client ne porte le numéro | **pas une erreur** : `ignored_items[]` avec `reason: "not_found"`, le lot continue |

* `success = false` **uniquement** si **aucun** numéro n'a pu être examiné
  (tout est en `failed`) — un lot partiellement en erreur reste une réponse
  `200` exploitable ;
* le SQL n'est jamais exposé : un `QueryException` est journalisé côté
  serveur (`Log::warning`) et résumé dans `errors[]`
  (« Conflit de données : indisponibilité impossible. »).

## 6. Exemples

### Succès — un numéro, tous les formats

```bash
curl -X POST https://HOST/api/v1/clients/convert-to-unavailable \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"phone": "+1-819-418-6550"}'
```

Réponse `200` :

```json
{"success":true,"data":{"received":1,"processed":1,"matched":1,"blocked":1,"ignored":0,"not_found":0,"already_unavailable":0,"blacklisted":0,"failed":0,"blocked_items":[{"index":0,"phone":"+1-819-418-6550","matched":1,"blocked":1,"returned_at":"2027-01-02 14:03:11"}],"ignored_items":[],"errors":[]}}
```

> **Rapport** : 1 reçu · **1 succès** (`processed`) · **1 bloqué** ·
> **0 ignoré** · **0 erreur**. Le client est `UNAVAILABLE` jusqu'au
> `returned_at` affiché, puis repasse `AVAILABLE` automatiquement.

### Succès — lot mixte (bloqué + ignorés + erreur)

```bash
curl -X POST https://HOST/api/v1/clients/convert-to-unavailable \
  -H 'Content-Type: application/json' \
  -d '{"phones": ["8194186550", "514-555-0000", "418-555-1212", "abc"]}'
```

```json
{"success":true,"data":{"received":4,"processed":3,"matched":1,"blocked":1,"ignored":2,"not_found":1,"already_unavailable":0,"blacklisted":1,"failed":1,"blocked_items":[{"index":0,"phone":"8194186550","matched":1,"blocked":1,"returned_at":"2027-01-02 14:03:11"}],"ignored_items":[{"index":1,"phone":"514-555-0000","reason":"not_found"},{"index":2,"phone":"418-555-1212","reason":"blacklisted"}],"errors":[{"index":3,"phone":"abc","error":"Numéro illisible : aucun chiffre détecté (ex. « 819-418-6550 »)."}]}}
```

> **Rapport** : 4 reçus · **3 succès** · **1 bloqué** · **2 ignorés**
> (1 introuvable + 1 en liste noire) · **1 erreur** (`"abc"`). `success`
> reste `true` : le lot a été examiné.

### Échec — tout le lot échoue (`success: false`, `200`)

```bash
curl -X POST https://HOST/api/v1/clients/convert-to-unavailable \
  -H 'Content-Type: application/json' \
  -d '{"phones": ["abc", ";;;"]}'
```

```json
{"success":false,"data":{"received":2,"processed":0,"matched":0,"blocked":0,"ignored":0,"not_found":0,"already_unavailable":0,"blacklisted":0,"failed":2,"blocked_items":[],"ignored_items":[],"errors":[{"index":0,"phone":"abc","error":"Numéro illisible : aucun chiffre détecté (ex. « 819-418-6550 »)."},{"index":1,"phone":";;;","error":"Numéro illisible : aucun chiffre détecté (ex. « 819-418-6550 »)."}]}}
```

### Échec — corps refusé (`422`, rien n'est modifié)

```bash
curl -X POST https://HOST/api/v1/clients/convert-to-unavailable \
  -H 'Content-Type: application/json' \
  -d '{"phones": []}'
```

```json
{"success":false,"error":"Aucun client à rendre indisponible (tableau vide)."}
```

Autres 422 : `{"foo": "bar"}` (enveloppe inconnue), `{"phone": "   "}`
(champ vide), `{"phone": 8194186550}` (non chaîne), corps non JSON, lot
> `PUBLIC_API_MAX_ITEMS`.

## 7. Retrait de l'endpoint (prévu)

Le endpoint est **temporaire** : pour le retirer, supprimer dans cet ordre —

1. `backend/routes/api/shared.php` → la route `clients/convert-to-unavailable` ;
2. `backend/config/cors.php` → l'entrée `api/v1/clients/convert-to-unavailable` ;
3. `PublicClientController` → `convertToUnavailable()` + `phoneItems()` ;
4. `Client` → `bulkUnavailableFromPhone()`, `unavailableByPhone()`,
   `clientsByPhone()`, `phoneFromItem()`, `normalizePhone()`,
   `PHONE_ITEM_KEYS` ;
5. `backend/tests/Feature/PublicClientConvertToUnavailableTest.php` et
   `backend/tests/Feature/ClientBulkUnavailableFromPhoneTest.php` ;
6. `docs/RULES.md` §10 (ligne du tableau) et §12 (paragraphe),
   `docs/public_api.md`, ce fichier.

Puis `php artisan route:clear` (ou `route:cache` en déploiement) pour
rejouer le cache de routes.
