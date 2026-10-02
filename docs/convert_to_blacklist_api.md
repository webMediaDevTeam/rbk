# API publique — Conversion en liste noire par nom

> ⚠️ **Endpoint public temporaire** — aucune authentification, appelable par
> n'importe qui depuis le navigateur (CORS ouvert sur `api/*`). Il est prévu
> pour être **retiré** : procédure de suppression en §7.

Même surface publique que `clients/bulk-upsert` et `clients/bulk-delete`
(spec : `docs/public_api.md`, règles métier : `docs/RULES.md` §12) : pas de
`auth:sanctum`, CORS via `config/cors.php`, lot borné par
`PUBLIC_API_MAX_ITEMS` (défaut **1000**, `config/public_api.php`).

## 1. Route

```php
// backend/routes/api/shared.php — déclarée avant toute route `clients/{…}`
Route::post('clients/convert-to-blacklist', [PublicClientController::class, 'convertToBlacklist']);
```

| Élément | Fichier |
|---|---|
| Route | `backend/routes/api/shared.php` |
| Contrôleur | `backend/app/Http/Controllers/Api/V1/Shared/PublicClientController.php` → `convertToBlacklist()` |
| Modèle | `backend/app/Models/Client.php` → `convertToBlacklistFromName()` / `blacklistByName()` |
| CORS | `backend/config/cors.php` → `paths[]` (trace de la route ouverte) |
| Tests | `backend/tests/Feature/PublicClientConvertToBlacklistTest.php` (15 tests) |

```
POST /api/v1/clients/convert-to-blacklist
```

## 2. Corps de requête (4 formes acceptées)

```jsonc
{"name": "Entreprises Richard Forget & Fils Inc."}          // 1 nom
{"names": ["Nom A", "Nom B"]}                                // lot de noms
{"clients": [{"name": "Nom A"}, {"enterprise_name": "Nom B"}]} // enveloppe commune
["Nom A", "Nom B"]                                           // liste JSON nue
```

* **objet item** → première clé de nom reconnue (forme normalisée : casse et
  ponctuation ignorées) : `name`, `nom`, `enterprise_name`, `entreprise`,
  `intervenant_name`, `Nom de l'intervenant / Entreprise` ;
* **chaîne item** → le nom lui-même ;
* la valeur est nettoyée comme à l'import (`Client::cleanText()` : espaces
  réduits, quotes de protection retirées) pour viser la forme stockée ;
* corps non JSON, enveloppe inconnue, tableau vide, `name` vide ou non
  chaîne, lot trop long → **`422`** (rien n'est modifié).

## 3. Recherche du nom (colonne + casse)

```sql
WHERE LOWER(enterprise_name) = LOWER(:nom)
   OR LOWER(name)            = LOWER(:nom)
```

* comparaison **exacte** (pas de `LIKE`, pas de troncature) et **insensible à
  la casse des deux côtés** — la casse et les accents sont ceux de la base
  (collation MySQL) ;
* les deux colonnes sont visées : le nom d'entreprise **ou** le nom du client ;
* un même nom peut correspondre à **plusieurs lignes** : **toutes** sont
  converties (aucune notion de « première occurrence ») ;
* **aucune correspondance → ignoré** : `not_found` (donc `ignored`), **ce
  n'est pas une erreur** et le lot continue avec le nom suivant ;
* lot **ré-exécutable** (idempotent) : un second passage rend
  `zapped = 0` et `ignored = received`, toujours sans erreur.

> `LOWER(colonne)` empêche l'usage d'un index sur ces deux colonnes : le
> endpoint est appelé par nom, en petit nombre — contrairement au
> `bulk-upsert`, aucune écriture massive n'est visée ici.

## 4. Geste métier appliqué (RULES §3.4 — cas 6)

Pour **chaque ligne** trouvée, dans **une transaction par nom** :

| Avant | Après |
|---|---|
| `is_blacklisted` | `true` |
| `status` | `BLACKLISTED` |
| `returned_at` | `NULL` |
| `rappels` du client | **supprimés** |
| `reservations` / `notes` | **conservés** (la liste noire ne supprime pas l'historique) |
| journal | note `BLACKLISTED`, émetteur **`SYSTEM`** (l'API n'a pas d'utilisateur) |

* une ligne **déjà** en liste noire (`is_blacklisted = true` **et**
  `status = BLACKLISTED`) n'est **ni réécrite ni re-journalisée** : elle
  compte dans `already_blacklisted`, son `updated_at` ne bouge pas ;
* l'invalidation des listes distinctes (`Client::forgetDistinctValues()`)
  est déclenchée par l'événement `saved` du modèle, comme ailleurs ;
* **aucun état applicatif n'est lu du corps** : la requête ne porte que des
  noms (pas de `status`, pas de `is_blacklisted` à imposer).

## 5. Réponse — rapport consolidé

```jsonc
{
  "success": true,
  "data": {
    "received": 6,               // noms reçus
    "processed": 5,              // ✅ SUCCÈS : noms traités sans erreur
    "zapped": 4,                 // ⚡ lignes clients réellement zappées
    "ignored": 3,                // 🚫 noms sans effet (introuvable + déjà fait)
    "not_found": 1,              //   dont introuvables  → reason "not_found"
    "already_blacklisted": 2,    //   dont déjà faits    → reason "already_blacklisted"
    "failed": 1,                 // ❌ ERREURS : noms en échec
    "matched": 5,                // lignes clients trouvées (toutes formes)

    "zapped_items": [
      {"index": 0, "name": "Entreprises Richard Forget & Fils Inc.", "matched": 2, "zapped": 2}
    ],
    "ignored_items": [
      {"index": 1, "name": "Inexistant Inc.",    "reason": "not_found"},
      {"index": 2, "name": "Déjà noir Inc.",     "reason": "already_blacklisted"}
    ],
    "errors": [
      {"index": 3, "name": null, "error": "Nom manquant : « name » (ou « enterprise_name »)."}
    ]
  }
}
```

| Compteur | Unité | Signification |
|---|---|---|
| `processed` | noms | **succès** = `received − failed` |
| `zapped` | lignes clients | mise(s) en liste noire effectivement écrite(s) |
| `ignored` | noms | sans effet : `not_found + already_blacklisted` |
| `failed` | noms | **erreurs** → détaillées dans `errors[]` |

* les 4 compteurs répondent à la question « combien de succès / ignorés /
  erreurs / zappés » d'un **seul coup d'œil** ; `received = processed +
  failed` ;
* **un nom introuvable n'est jamais une erreur** : il entre dans `ignored`
  (avec `reason: "not_found"`) et le lot continue ;
* `success = false` **uniquement** si **aucun** nom n'a pu être examiné (tout
  est en `failed`) — un lot partiellement en erreur reste une réponse `200`
  exploitable ;
* le SQL n'est jamais exposé : un `QueryException` est journalisé côté
  serveur (`Log::warning`) et résumé dans `errors[]`.

## 6. Exemple

```bash
curl -X POST https://HOST/api/v1/clients/convert-to-blacklist \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"name": "Entreprises Richard Forget & Fils Inc."}'
```

Réponse `200` :

```json
{"success":true,"data":{"received":1,"processed":1,"matched":1,"zapped":1,"ignored":0,"not_found":0,"already_blacklisted":0,"failed":0,"zapped_items":[{"index":0,"name":"Entreprises Richard Forget & Fils Inc.","matched":1,"zapped":1}],"ignored_items":[],"errors":[]}}
```

### Lot de noms (bulk)

```bash
curl -X POST https://HOST/api/v1/clients/convert-to-blacklist \
  -H 'Content-Type: application/json' \
  -d '{"names": ["Entreprises Richard Forget & Fils Inc.", "Inexistant Inc.", "Déjà noir Inc."]}'
```

```json
{"success":true,"data":{"received":3,"processed":3,"matched":1,"zapped":1,"ignored":2,"not_found":1,"already_blacklisted":1,"failed":0,"zapped_items":[{"index":0,"name":"Entreprises Richard Forget & Fils Inc.","matched":1,"zapped":1}],"ignored_items":[{"index":1,"name":"Inexistant Inc.","reason":"not_found"},{"index":2,"name":"Déjà noir Inc.","reason":"already_blacklisted"}],"errors":[]}}
```

> **Rapport** : 3 reçus · **3 succès** (`processed`) · **1 zappé** ·
> **2 ignorés** · **0 erreur** (`failed`).

## 7. Retrait de l'endpoint (prévu)

Le endpoint est **temporaire** : pour le retirer, supprimer dans cet ordre —

1. `backend/routes/api/shared.php` → la route `clients/convert-to-blacklist` ;
2. `backend/config/cors.php` → l'entrée `api/v1/clients/convert-to-blacklist` ;
3. `PublicClientController` → `convertToBlacklist()` + `nameItems()` ;
4. `Client` → `convertToBlacklistFromName()`, `blacklistByName()`,
   `blacklistNameFromItem()`, `BLACKLIST_NAME_KEYS` ;
5. `backend/tests/Feature/PublicClientConvertToBlacklistTest.php` ;
6. `docs/RULES.md` §10 (ligne du tableau) et §12 (paragraphe), ce fichier.

Puis `php artisan route:clear` (ou `route:cache` en déploiement) pour
rejouer le cache de routes.
