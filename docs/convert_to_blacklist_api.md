# API publique — Conversion en liste noire (par nom **ou** par licence)

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
| Modèle | `backend/app/Models/Client.php` → `convertToBlacklistFromPayload()` / `convertToBlacklistFromName()` / `convertToBlacklistFromLicence()` / `applyBlacklist()` |
| CORS | `backend/config/cors.php` → `paths[]` (trace de la route ouverte) |
| Tests | `backend/tests/Feature/PublicClientConvertToBlacklistTest.php` (23 tests) |
| Script de campagne | `../blacklist/upload_blacklist_licences.py` (mode **licence**, lots de 500) |

```
POST /api/v1/clients/convert-to-blacklist
```

La cible d'une conversion est **le nom** (mode historique) **ou la licence**
(mode campagne) : c'est **l'enveloppe** du corps qui choisit le mode (§2).

## 2. Corps de requête (enveloppes → mode)

### Mode **licence** — enveloppe licence

```jsonc
{"licence": "5747508901"}                                  // 1 licence
{"licences": ["5747508901", "5747-5089-01"]}               // lot de licences
```

### Mode **nom** — enveloppe nom

```jsonc
{"name": "Entreprises Richard Forget & Fils Inc."}          // 1 nom
{"names": ["Nom A", "Nom B"]}                                // lot de noms
```

### Mode **auto** — enveloppe « clients » ou liste JSON nue

```jsonc
{"clients": [{"Licence": "5747508901", "Nom de l'intervenant / Entreprise": "Nom A"}]}
["5747508901", "Nom B"]                                      // liste JSON nue
```

En mode **auto**, la cible est déduite **item par item** :

1. si l'item est un **objet** portant une clé de licence → licence ;
2. sinon le **nom** est cherché ; s'il ne correspond à aucune ligne, la valeur
   est réessayée **comme licence** (une liste de chaînes `["5747508901"]` est
   donc traitée comme des licences, une liste de noms comme des noms) ;
3. sans nom ni licence → item en `failed`.

* **objet item** → première clé reconnue (forme normalisée : casse et
  ponctuation ignorées) ;
* **chaîne item** → la cible elle-même ;
* la valeur est nettoyée comme à l'import (`Client::cleanText()` : espaces
  réduits, quotes de protection retirées) pour viser la forme stockée ;
* corps non JSON, enveloppe inconnue, tableau vide, cible vide ou non
  chaîne, lot trop long → **`422`** (rien n'est modifié).

| Mode | Clés de cible reconnues (forme normalisée) |
|---|---|
| nom | `name`, `nom`, `enterprise_name`, `entreprise`, `intervenant_name`, `Nom de l'intervenant / Entreprise` |
| licence | `licence`, `licences`, `licence_number`, `numero_licence`, `licence_propre`, `licence_propre_numero` |

## 3. Recherche de la cible

### 3.1 Nom (colonne + casse)

```sql
WHERE LOWER(enterprise_name) LIKE '%' || LOWER(:nom) || '%'
   OR LOWER(name)            LIKE '%' || LOWER(:nom) || '%'
```

* **insensible à la casse des deux côtés** — la casse et les accents sont
  ceux de la base (collation MySQL) ;
* **partielle** : le nom est une **sous-chaîne**. Une saisie comme `Forget`
  atteint toutes les entreprises qui la contiennent ; c'est le comportement
  historique de l'endpoint par nom (le mode licence, lui, est une égalité
  exacte) ;
* les deux colonnes sont visées : le nom d'entreprise **ou** le nom du client ;
* `LIKE` sur une colonne non indexée est un balayage : l'endpoint est
  appelé en petit nombre — contrairement au `bulk-upsert`, aucune écriture
  massive n'est visée ici.

### 3.2 Licence (« Licence (propre) », sinon « Licence »)

| Valeur reçue | Colonnes visées |
|---|---|
| **numérique pure** (`5747508901`) | `licence_propre_numero` (entier) **et** `licence_number` (texte) |
| **texte** (`5747-5089-01`, `RB-123`) | `licence_number` **seulement** |

* **égalité exacte**, dans les deux cas ;
* une valeur numérique couvre les deux imports possibles (le registre peut
  avoir stocké « Licence (propre) » dans l'une ou l'autre colonne) ;
* une valeur **textuelle** ne va jamais chercher un numéro RBQ dans
  `licence_propre_numero` : `RB-5747508909` ne valide **pas** le client
  `5747508909` (pas de faux positif sur un préfixe).

### 3.3 Commun aux deux cibles

* une même cible peut correspondre à **plusieurs lignes** : **toutes** sont
  converties (aucune notion de « première occurrence ») ;
* **aucune correspondance → ignoré** : `not_found` (donc `ignored`), **ce
  n'est pas une erreur** et le lot continue avec la cible suivante ;
* lot **ré-exécutable** (idempotent) : un second passage rend
  `zapped = 0` et `ignored = received`, toujours sans erreur.

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
* le libellé de la note reprend la cible : `Liste noire (API publique) : <nom>`
  ou `Liste noire (API publique) : licence <licence>` ;
* l'invalidation des listes distinctes (`Client::forgetDistinctValues()`)
  est déclenchée par l'événement `saved` du modèle, comme ailleurs ;
* **aucun état applicatif n'est lu du corps** : la requête ne porte que des
  cibles (pas de `status`, pas de `is_blacklisted` à imposer).

## 5. Réponse — rapport consolidé

```jsonc
{
  "success": true,
  "data": {
    "received": 6,               // cibles reçues
    "processed": 5,              // ✅ SUCCÈS : cibles traitées sans erreur
    "zapped": 4,                 // ⚡ lignes clients réellement zappées
    "ignored": 3,                // 🚫 cibles sans effet (introuvable + déjà fait)
    "not_found": 1,              //   dont introuvables  → reason "not_found"
    "already_blacklisted": 2,    //   dont déjà faits    → reason "already_blacklisted"
    "failed": 1,                 // ❌ ERREURS : cibles en échec
    "matched": 5,                // lignes clients trouvées (toutes formes)

    "zapped_items": [
      {"index": 0, "type": "licence", "key": "5747508901", "name": null,
       "licence": "5747508901", "matched": 1, "zapped": 1}
    ],
    "ignored_items": [
      {"index": 1, "type": "licence", "key": "9999999999", "name": null,
       "licence": "9999999999", "reason": "not_found"},
      {"index": 2, "type": "name", "key": "Déjà noir Inc.", "name": "Déjà noir Inc.",
       "licence": null, "reason": "already_blacklisted"}
    ],
    "errors": [
      {"index": 3, "type": "licence", "key": null, "name": null, "licence": null,
       "error": "Licence manquante : « licence » (ou « Licence (propre) »)."}
    ]
  }
}
```

Chaque ligne de `zapped_items[]` / `ignored_items[]` / `errors[]` porte la
cible résolue : `type` (`name` ou `licence`), `key` (valeur brute),
puis `name` **ou** `licence` — l'autre valant `null`. `index` est la position
dans le lot (les deux champs sont `null` pour un item inexploitable).

| Compteur | Unité | Signification |
|---|---|---|
| `processed` | cibles | **succès** = `received − failed` |
| `zapped` | lignes clients | mise(s) en liste noire effectivement écrite(s) |
| `ignored` | cibles | sans effet : `not_found + already_blacklisted` |
| `failed` | cibles | **erreurs** → détaillées dans `errors[]` |

* les 4 compteurs répondent à la question « combien de succès / ignorés /
  erreurs / zappés » d'un **seul coup d'œil** ; `received = processed +
  failed` ;
* **une cible introuvable n'est jamais une erreur** : elle entre dans `ignored`
  (avec `reason: "not_found"`) et le lot continue ;
* `success = false` **uniquement** si **aucune** cible n'a pu être examinée
  (tout est en `failed`) — un lot partiellement en erreur reste une réponse
  `200` exploitable ;
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
{"success":true,"data":{"received":1,"processed":1,"matched":1,"zapped":1,"ignored":0,"not_found":0,"already_blacklisted":0,"failed":0,"zapped_items":[{"index":0,"type":"name","key":"Entreprises Richard Forget & Fils Inc.","name":"Entreprises Richard Forget & Fils Inc.","licence":null,"matched":1,"zapped":1}],"ignored_items":[],"errors":[]}}
```

### Lot de noms (bulk)

```bash
curl -X POST https://HOST/api/v1/clients/convert-to-blacklist \
  -H 'Content-Type: application/json' \
  -d '{"names": ["Entreprises Richard Forget & Fils Inc.", "Inexistant Inc.", "Déjà noir Inc."]}'
```

```json
{"success":true,"data":{"received":3,"processed":3,"matched":1,"zapped":1,"ignored":2,"not_found":1,"already_blacklisted":1,"failed":0,"zapped_items":[{"index":0,"type":"name","key":"Entreprises Richard Forget & Fils Inc.","name":"Entreprises Richard Forget & Fils Inc.","licence":null,"matched":1,"zapped":1}],"ignored_items":[{"index":1,"type":"name","key":"Inexistant Inc.","name":"Inexistant Inc.","licence":null,"reason":"not_found"},{"index":2,"type":"name","key":"Déjà noir Inc.","name":"Déjà noir Inc.","licence":null,"reason":"already_blacklisted"}],"errors":[]}}
```

> **Rapport** : 3 reçues · **3 succès** (`processed`) · **1 zappé** ·
> **2 ignorés** · **0 erreur** (`failed`).

### Lot de licences (campagne)

```bash
curl -X POST https://HOST/api/v1/clients/convert-to-blacklist \
  -H 'Content-Type: application/json' \
  -d '{"licences": ["5747508901", "5747-5089-01", "9999999999"]}'
```

```json
{"success":true,"data":{"received":3,"processed":3,"matched":1,"zapped":1,"ignored":2,"not_found":0,"already_blacklisted":1,"failed":0,"zapped_items":[{"index":0,"type":"licence","key":"5747508901","name":null,"licence":"5747508901","matched":1,"zapped":1}],"ignored_items":[{"index":1,"type":"licence","key":"5747-5089-01","name":null,"licence":"5747-5089-01","reason":"already_blacklisted"},{"index":2,"type":"licence","key":"9999999999","name":null,"licence":"9999999999","reason":"not_found"}],"errors":[]}}
```

> Les deux formes de la **même** entreprise (`5747-5089-01` et sa version
> normalisée `5747508901`) sont acceptées : la première qui correspond écrit,
> l'autre revient en `already_blacklisted`. C'est ce qui permet au script de
> campagne (`../blacklist/upload_blacklist_licences.py`) d'envoyer les deux
> colonnes du registre sans risquer de doublon ni de faux positif.

## 7. Retrait de l'endpoint (prévu)

Le endpoint est **temporaire** : pour le retirer, supprimer dans cet ordre —

1. `backend/routes/api/shared.php` → la route `clients/convert-to-blacklist` ;
2. `backend/config/cors.php` → l'entrée `api/v1/clients/convert-to-blacklist` ;
3. `PublicClientController` → `convertToBlacklist()`, `nameItems()`,
   `licenceItems()` ;
4. `Client` → `convertToBlacklistFromPayload()`,
   `convertToBlacklistFromName()`, `convertToBlacklistFromLicence()`,
   `convertToBlacklistPayload()`, `blacklistByName()`,
   `blacklistByLicence()`, `clientsMatchingName()`,
   `clientsMatchingLicence()`, `applyBlacklist()`, `blacklistNameFromItem()`,
   `blacklistLicenceFromItem()`, `blacklistReportTarget()`,
   `blacklistTargetFromItem()`, `BLACKLIST_NAME_KEYS`,
   `BLACKLIST_LICENCE_KEYS`, `BLACKLIST_MODE_*` ;
5. `backend/tests/Feature/PublicClientConvertToBlacklistTest.php` ;
6. `../blacklist/upload_blacklist_licences.py` +
   `../blacklist/generer_rapport_blacklist.py` ;
7. `docs/RULES.md` §10 (ligne du tableau) et §12 (paragraphe), ce fichier.

Puis `php artisan route:clear` (ou `route:cache` en déploiement) pour
rejouer le cache de routes.
