# API publique des clients — import (upsert) & suppression (delete)

> ⚠️ **Surface M2M** — pas d'utilisateur ni de session (`auth:sanctum`
> absent) : la barrière est la **clé partagée** `X-Api-Key`
> (`EXTERNAL_SYSTEM_API_KEY`, middleware `VerifyExternalSystemKey`,
> échec fermé = `401` sans clé valide, y compris serveur non configuré).
> CORS ouvert sur `api/*` (`allowed_headers` = `*`).

Ils forment le webhook **scraper / n8n** : import par lots d'un côté,
suppression propre de l'autre, avec la même garantie de bout en bout (un
client à données liées n'est jamais supprimé).

Règles métier : [`docs/RULES.md`](RULES.md) §12 (API publique).
Collection Postman importable : [`docs/public_api.postman_collection.json`](public_api.postman_collection.json).

---

## 0. Référence rapide

| Méthode | Route | Objet | Tests |
|---|---|---|---|
| `POST` | `/api/v1/clients/bulk-upsert` | import / mise à jour d'un prospect | 10 |
| `POST` · `DELETE` | `/api/v1/clients/bulk-delete` | suppression — **données liées ignorées** | 10 |

Les deux verbes de `bulk-delete` pointent sur la **même action**.

| Élément | Fichier |
|---|---|
| Routes | `backend/routes/api/shared.php` (déclarées **avant** toute route `clients/{…}`) |
| Contrôleur | `backend/app/Http/Controllers/Api/V1/Shared/PublicClientController.php` → `bulkUpsert()` / `bulkDelete()` |
| Modèle | `backend/app/Models/Client.php` → `bulkUpsertFromScraperPayload()` / `upsertFromScraperPayload()` / `bulkDeleteFromScraper()` / `scraperLookupKey()` |
| CORS | `backend/config/cors.php` → `paths[]` |
| Limite de lot | `backend/config/public_api.php` → `max_items` |
| Tests | `PublicClientBulkUpsertTest.php` (10) · `PublicClientBulkDeleteTest.php` (10) |

```
POST   /api/v1/clients/bulk-upsert
POST   /api/v1/clients/bulk-delete
DELETE /api/v1/clients/bulk-delete
```

---

## 1. Surface commune

### 1.1 Authentification & CORS

| Élément | Valeur |
|---|---|
| Authentification | **aucune** — aucun header `Authorization` |
| CORS | `config/cors.php` → `paths` = `api/*` (la route `bulk-upsert` y est listée pour tracer l'ouverture), origines `*`, méthodes `*`, en-têtes `*` |
| Préflight | `OPTIONS` sur la route, sans corps → 2xx + `Access-Control-Allow-*` |
| `Accept` conseillé | `application/json` |

### 1.2 Corps de requête

Tout corps est **du JSON** (`Content-Type: application/json`) sous l'une des
deux enveloppes :

```jsonc
{"clients": [ … ]}    // ou {"licences": [...]} — dépend de l'endpoint
[ … ]                 // liste JSON nue
```

### 1.3 Réponse

```jsonc
// 200 — geste exécuté, éventuellement partiellement
{ "success": true, "data": { /* rapport consolidé */ } }

// 422 — corps refusé AVANT toute écriture
{ "success": false, "error": "Aucun client à importer (tableau vide)." }
```

| Concept | Définition |
|---|---|
| `received` | items reçus dans le corps |
| `processed` | items **examinés sans erreur** = **succès** |
| `failed` | items en **échec** → `data.errors[]` |
| `success` | `processed > 0` — un lot **partiellement** en erreur reste `200` + `success: true` |

Les **SQL ne sont jamais exposés** : un `QueryException` est journalisé côté
serveur (`Log::warning`) et résumé en clair dans `errors[]`.

### 1.4 Limite de lot

`PUBLIC_API_MAX_ITEMS` (`config/public_api.php` → `max_items`, défaut
**1000**) : au-delà, `422` et **rien n'est écrit** — l'appelant reprend par
lots plus petits.

### 1.5 Idempotence

Les deux endpoints sont **ré-exécutables** : une seconde passe sur les
mêmes cibles ne duplique ni ne casse rien (`unchanged` côté upsert,
`skipped` / `missing` côté suppression).

---

## 2. `POST /clients/bulk-upsert` — import scraper / n8n

### 2.1 Corps

```jsonc
{"clients": [ /* un objet par prospect */ ]}   // enveloppe recommandée
[ { … }, { … } ]                               // liste JSON nue d'objets
```

Les items doivent être des **objets JSON** : une chaîne nue →
`Enregistrement attendu : un objet JSON par ligne.` (comptée dans `failed`).

### 2.2 Clé d'upsert

1. **`licence_number`** (`Licence`) — clé primaire de l'upsert ;
2. en repli **`licence_propre_numero`** (`Licence (propre)`, colonne
   `UNIQUE`) quand le payload ne porte pas de numéro de licence classique ;
3. sinon → `Clé d'upsert manquante : « Licence » (ou « Licence (propre) » en repli).`

Les clés du payload sont normalisées : **casse et ponctuation ignorées**, et
les **colonnes `snake_case`** sont acceptées en parallèle des clés françaises
(`licence_number` ≡ `Licence`, `municipality` ≡ `Municipalité`, …).

### 2.3 Colonnes acceptées (`Client::PAYLOAD_MAP`)

| Clé du payload | Colonne | Type |
|---|---|---|
| `Licence` | `licence_number` | texte |
| `Licence (propre)` | `licence_propre_numero` | entier |
| `Nom de l'intervenant / Entreprise` | `enterprise_name` | texte |
| `Statut de la licence` | `licence_status` | texte |
| `NEQ` | `neq` | texte |
| `Adresse complète` | `full_address` | texte |
| `Municipalité` | `municipality` | texte |
| `Région administrative` | `administrative_region` | texte |
| `Téléphone` | `phone` | texte |
| `Courriel` | `email` | texte |
| `Nombre de répondants` | `respondent_count` | entier |
| `Répondants / Interlocuteurs (Qualifications)` | `respondents` | tableau |
| `Nombre de sous-catégories` | `sub_category_count` | entier |
| `Catégories et sous-catégories autorisées` | `authorized_categories` | tableau |
| `Cautionnement (Compagnie / Association)` | `surety_company` | texte |
| `Montant de la caution ($)` | `surety_amount` | décimal |
| `Date de début / délivrance` | `licence_start_date` | `YYYY-MM-DD` |
| `Date de fin / paiement annuel` | `licence_end_date` | `YYYY-MM-DD` |
| `Source` | `source` | texte |

**`source` — origine du prospect** (répertoire `sources`, même valeur que
`enterprises.source`, sans rapport avec RingCentral) : colonne `NOT NULL
DEFAULT 'Affaire'`. Une clé `Source` **vide ou absente n'efface jamais** la
valeur en place — création sans clé → `Affaire`, mise à jour sans clé → valeur
courante conservée. Aucune saisie UI : la valeur est lue dans les réponses
clients (listes + détail) et affichée en lecture seule dans la fiche.

Normalisations (`castPayloadValue()` + `scrubAttributes()`), le webhook
**normalise sans valider** :

* espaces réduits (insécables compris) et guillemets de protection retirés
  (`« Texte »` → `Texte`), chaîne vide → `NULL` ;
* listes : tableau, JSON ou chaîne séparée par `|` — dédoublonnées, codes de
  catégorie retirés (`[GPC] Libellé`, `1.23 — Libellé`), libellés en
  MAJUSCULES ramenés à la casse normale ;
* dates : `Carbon::parse()` → `YYYY-MM-DD`, valeur illisible → `NULL` ;
* montants (`"$12 500,00"`) → `12500.0` ; compteurs et `Licence (propre)` →
  entier ; téléphone / courriel / NEQ nettoyés.

Dérivations (mêmes conventions que l'import JSON) : `categories` ←
`authorized_categories` ; `licence_propre` ← `licence_propre_numero`
renseigné ; `name` et `intervenant_name` héritent d'`enterprise_name` quand
ils sont absents ; `Cautionnement` accepte plusieurs valeurs →
`cautionnement_compagnie[]` + première valeur dans `surety_company`.

### 2.4 Aucun état applicatif n'est accepté

`status`, `is_blacklisted`, `returned_at` et la réservation sont **exclus**
du payload : à la création, la ligne naît `AVAILABLE` / non blacklistée /
`returned_at = NULL`. **Le webhook ne peut ni réserver, ni blacklister, ni
rendre indisponible un prospect** — ces gestes ont leurs propres endpoints
temporaires (voir « Voir aussi » §7).

### 2.5 Réponse

```jsonc
{
  "success": true,
  "data": {
    "received": 3, "processed": 3,
    "created": 1, "updated": 1, "unchanged": 1,   // créés / modifiés / identiques
    "skipped_manual": 0,                          // fiches saisies à la main (jamais réécrites)
    "failed": 0,
    "errors": []                                   // {index, licence_number, error}
  }
}
```

* transaction **par ligne** : un item invalide ou un conflit d'unicité entre
  dans `failed` sans annuler le reste du lot ;
* ligne **identique** → `unchanged` : `updated_at` ne bouge donc pas entre
  deux resynchronisations ;
* fiche **modifiée à la main** (`is_manually_updated = true`, posée par
  `PATCH commercials/clients/{id}/phone`) → `skipped_manual` : la ligne est
  laissée telle quelle, **aucun** champ n'est complété ni réécrit ;
* invalidation des caches de listes distinctes déclenchée par le modèle ;
* `Conflit de données : numéro de licence déjà utilisé par un autre client.`
  couvre l'unicité de `licence_propre_numero`.

### 2.6 Exemple

```bash
curl -X POST http://localhost:8000/api/v1/clients/bulk-upsert \
  -H 'X-Api-Key: $EXTERNAL_SYSTEM_API_KEY' \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"clients":[
        {"Licence":"5747-5089-01","Licence (propre)":5747508901,
         "Nom de l'\''intervenant / Entreprise":"10095800 canada Inc.",
         "Municipalité":"Québec","Téléphone":"418-555-0101",
         "Nombre de répondants":3,
         "Catégories et sous-catégories autorisées":"Béton|Excavation",
         "Date de début / délivrance":"2024-01-01",
         "Date de fin / paiement annuel":"2025-12-31"},
        {"licence_number":"1100-3571-01","enterprise_name":"Autre entreprise Inc."}
      ]}'
```

Réponse :

```json
{"success":true,"data":{"received":2,"processed":2,"created":2,"updated":0,"unchanged":0,"skipped_manual":0,"failed":0,"errors":[]}}
```

---

## 3. `POST` / `DELETE` `clients/bulk-delete` — suppression

### 3.1 Corps (3 formes)

```jsonc
{"licences": ["5747-5089-01", "1100-3571-01"]}       // enveloppe licence
{"clients": [{"Licence": "5747-5089-01"}, {"Licence (propre)": 4001}]}
["5747-5089-01", "4001"]                              // liste JSON nue
```

| Item | Clé lue |
|---|---|
| chaîne nue | `licence_number` |
| objet | `Licence` → `licence_number`, sinon `Licence (propre)` → `licence_propre_numero` |
| aucune clé | `Clé manquante : « Licence » (ou « Licence (propre) »).` |

### 3.2 Garde-fou : les données liées **ne sont jamais supprimées**

`reservations`, `notes` et `rappels` sont tous `cascadeOnDelete()` : supprimer
un client qui en porterait aurait détruit son historique. Pour chaque item,
dans **sa propre transaction** (ligne verrouillée `lockForUpdate()`,
comptage des liens) :

1. client **avec au moins une donnée liée** → `skipped` : aucune suppression,
   les comptes par table sont rendus, et **la boucle passe au client suivant** ;
2. client **vierge** → suppression Eloquent → `deleted` (l'événement `deleted`
   invalide le cache des listes distinctes) ;
3. licence inexistante → `missing` (**pas une erreur**) ;
4. item inexploitable → `failed`.

### 3.3 Réponse

```jsonc
{
  "success": true,
  "data": {
    "received": 3, "processed": 3,
    "deleted": 1, "skipped": 1, "missing": 1, "failed": 0,
    "skipped_items": [
      {"index": 1, "licence_number": "5747-5089-01",
       "linked": {"reservations": 2, "notes": 5, "rappels": 1}}
    ],
    "missing_items": [{"index": 2, "licence_number": "1100-3571-01"}],
    "errors": []                                     // {index, licence_number, error}
  }
}
```

### 3.4 Exemple

```bash
curl -X DELETE http://localhost:8000/api/v1/clients/bulk-delete \
  -H 'X-Api-Key: $EXTERNAL_SYSTEM_API_KEY' \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"licences":["5747-5089-01","1100-3571-01"]}'
```

Réponse :

```json
{"success":true,"data":{"received":2,"processed":2,"deleted":0,"skipped":1,"missing":1,"failed":0,"skipped_items":[{"index":0,"licence_number":"5747-5089-01","linked":{"reservations":2,"notes":5,"rappels":1}}],"missing_items":[{"index":1,"licence_number":"1100-3571-01"}],"errors":[]}}
```

---

## 4. Messages `422` (corps refusé, rien n'est écrit)

| Message | Endpoint | Cause |
|---|---|---|
| `Corps JSON invalide : un tableau de clients est attendu.` | les deux | corps non JSON / non tableau |
| `Format attendu : {"clients": [...]} ou une liste JSON.` | upsert | enveloppe inconnue |
| `Format attendu : {"licences": [...]} / {"clients": [...]} ou une liste JSON.` | delete | enveloppe inconnue |
| `Aucun client à importer (tableau vide).` | upsert | `[]` |
| `Aucun client à supprimer (tableau vide).` | delete | `[]` |
| `1500 enregistrements reçus : maximum 1000 par appel (PUBLIC_API_MAX_ITEMS).` | les deux | lot trop long |

Les erreurs **d'item** (réponse `200`, dans `data.errors[]`) portent le
vocabulaire métier :

| Message | Endpoint |
|---|---|
| `Clé d'upsert manquante : « Licence » (ou « Licence (propre) » en repli).` | upsert |
| `Enregistrement attendu : un objet JSON par ligne.` | upsert |
| `Conflit de données : numéro de licence déjà utilisé par un autre client.` | upsert |
| `Clé manquante : « Licence » (ou « Licence (propre) »).` | delete |
| `Conflit de données : suppression impossible.` | delete |

---

## 5. Configuration

| Variable `.env` | `config/public_api.php` | Défaut | Effet |
|---|---|---|---|
| `PUBLIC_API_MAX_ITEMS` | `max_items` | `1000` | taille max d'un lot |
| `CORS_ALLOWED_ORIGINS` | `cors.allowed_origins` | `*` | origines autorisées (jamais de slash final) |

Source : `backend/.env.example`. Après modification d'une route ou du CORS :
`php artisan route:clear` (ou `route:cache` en déploiement).

---

## 6. Tests

| Fichier | Tests | Ce qu'ils couvrent |
|---|---|---|
| `backend/tests/Feature/PublicClientBulkUpsertTest.php` | 10 | accès invité, enveloppe `clients` + liste JSON nue, clés françaises + `snake_case`, resync sans état applicatif, ligne identique → `unchanged`, repli `Licence (propre)`, échecs partiels / lot **entier** en échec (`success: false`), 422 (formats + lot), preflight CORS |
| `backend/tests/Feature/PublicClientBulkDeleteTest.php` | 10 | accès invité, verbe `DELETE`, `skip` + suite, client à notes seules, licence absente, corps chaîne / objet / liste nue, 422, lot borné, invalidation du cache, preflight CORS |

```bash
docker exec rbqbot-backend-dev php artisan test \
  --filter='PublicClientBulkUpsertTest|PublicClientBulkDeleteTest'
```

---

## 7. Collection Postman

**Importer** : Postman → *Collections → Import* →
[`docs/public_api.postman_collection.json`](public_api.postman_collection.json).

* variable `base_url` : `http://localhost:8000` par défaut — passer
  `http://zdigia.com` (ou l'hôte de prod) pour cibler l'API déployée ;
* variables `licence_1`, `licence_2`, `licence_absente` : les cibles des
  requêtes de suppression (la 3e est volontairement absente → `missing`) ;
* deux dossiers, un par endpoint, 5 requêtes chacun :

  | Dossier | Requêtes |
  |---|---|
  | **Bulk upsert (import)** | enveloppe `clients` · liste JSON nue · `422` lot trop gros · `422` corps vide · préflight CORS |
  | **Bulk delete (suppression)** | enveloppe `licences` (`POST`) · enveloppe `clients` (`DELETE`) · liste JSON nue · `422` lot trop gros · préflight CORS |

* **script de test partagé** au niveau collection : sur tout `200`, il
  vérifie la forme `{success, data}`, la présence des compteurs, les
  invariants `received === processed + failed`,
  `success === (processed > 0)` et `failed === errors.length` ; sur `422`, il
  vérifie `{success: false, error}` **sans** `data`. Il s'auto-exempt pour le
  préflight (`204`). Chaque requête ajoute ensuite ses **assertions propres**
  (`pm.test`) sur ses compteurs (`created + updated + unchanged +
  skipped_manual === processed`, `deleted + skipped + missing === processed`, forme de
  `skipped_items[].linked`, etc.).

> ⚠️ **Ordre d'exécution** — le dossier d'import doit passer **avant** celui de
> suppression : un client tout juste importé est *vierge* (aucune donnée liée)
> et sera donc réellement supprimé. Pour observer un `skipped`, viser un client
> ayant déjà une réservation, une note ou un rappel.
>
> Le test `422 — lot trop gros` génère 1001 lignes via `{{#repeat}}` : le corps
> est volumineux à résoudre, c'est normal.

> **Voir aussi** — les trois endpoints publics **temporaires** ont leur spec
> propre et ne figurent pas dans cette doc ni dans la collection :
> [`convert_to_blacklist_api.md`](convert_to_blacklist_api.md),
> [`convert_to_unavailable_api.md`](convert_to_unavailable_api.md),
> [`create_no_reservations_api.md`](create_no_reservations_api.md).
