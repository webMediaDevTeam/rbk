# API publique — Fausses réservations « NON » (données de préparation)

> ⚠️ **Endpoint public temporaire** — aucune authentification, appelable par
> n'importe qui depuis le navigateur (CORS ouvert sur `api/*`). Il est prévu
> pour être **retiré** : procédure de suppression en §8.

Même surface publique que `clients/bulk-upsert`, `clients/bulk-delete`,
`clients/convert-to-blacklist` et `clients/convert-to-unavailable` (spec :
`docs/public_api.md`, règles métier : `docs/RULES.md` §12) : pas de
`auth:sanctum`, CORS via `config/cors.php`, lot borné par
`PUBLIC_API_MAX_ITEMS` (défaut **1000**, `config/public_api.php`).

**Objet** : écrire une ligne `reservations` au statut **`NO`** par client
visé, attribuée à **un seul employé** — afin de fabriquer l'historique
« cet employé a répondu NON » que les écrans consomment.

> ⚠️ **Ce n'est pas le workflow d'appel.** Ce endpoint est une **donnée de
> préparation** : il écrit la ligne de réservation et **rien d'autre**. Le
> geste métier complet (« NON » → note `NO`, `returned_at` à 3 mois,
> auto-liste-noire quand tous les employés actifs ont répondu NON — RULES §3)
> reste produit **exclusivement** par `CallWorkflowService::apply()` à la
> suite d'un véritable appel. Voir §4 pour le détail de ce qui n'est **pas**
> fait.

## 1. Route

```php
// backend/routes/api/shared.php — déclarée avant toute route `clients/{…}`
Route::post('clients/create-no-reservations', [PublicClientController::class, 'createNoReservations']);
```

| Élément | Fichier |
|---|---|
| Route | `backend/routes/api/shared.php` |
| Contrôleur | `backend/app/Http/Controllers/Api/V1/Shared/PublicClientController.php` → `createNoReservations()` / `noReservationItems()` |
| Modèle | `backend/app/Models/Client.php` → `noReservationsFromItems()` / `noReservationsFromStatus()` / `noReservationsForTargets()` / `noReservationByTarget()` / `noReservationTargetFromItem()` |
| Employé attributaire | `backend/config/public_api.php` → `no_reservations_comercial_email` (défaut : `mohamed.khemir@apex-structures.tn`) |
| CORS | `backend/config/cors.php` → `paths[]` (trace de la route ouverte) |
| Tests | `backend/tests/Feature/PublicClientNoReservationsTest.php` (16 tests) |
| Script de campagne | `../blacklist/upload_no_reservations.py` + `../blacklist/generer_rapport_no_reservations.py` |

```
POST /api/v1/clients/create-no-reservations
```

## 2. Corps de requête

### 2.1 Mode « périmètre » — un statut, sans liste

```jsonc
{"status": "UNAVAILABLE"}                          // tous les clients du statut
{"status": "UNAVAILABLE", "after": "<uuid>"}       // suite du périmètre (curseur)
```

* `status` : un `Client::STATUS_*` (`AVAILABLE`, `RESERVED`, `UNAVAILABLE`,
  `BLACKLISTED`, `CONFIRMED`) — casse et espaces insensibles ; statut inconnu
  ou non chaîne → **`422`** ;
* `after` : identifiant **exclu**, tri par `id` croissant. Le geste ne
  modifie pas le statut du client : sans curseur, un second appel reverrait
  sur les mêmes lignes. La réponse rend donc `next_after` tant qu'il reste des
  clients au-delà du plafond (`PUBLIC_API_NO_RESERVATIONS_STATUS_LIMIT`,
  défaut **20 000**), `null` dès que le périmètre est couvert ;
* `status` **et** une liste de cibles dans le même corps → **`422`**
  (« fournissez `status`, **ou** une liste — pas les deux ») ;
* ce mode n'est **pas** borné par `PUBLIC_API_MAX_ITEMS` : il ne reçoit pas de
  liste du client, il la sélectionne lui-même.

### 2.2 Mode « liste » — des cibles

```jsonc
{"licence": "5747508901"}                          // 1 licence
{"licences": ["5747508901", "5747-5089-01"]}       // lot de licences
{"client_id": "01a0f79c-…"}                       // 1 client (uuid)
{"client_ids": ["01a0f79c-…", "01a0f79d-…"]}      // lot de clients
{"clients": [{"licence": "5747508901"}, {"client_id": "01a0f79c-…"}]}
["5747508901", "01a0f79c-…"]                      // liste JSON nue
```

| Cible | Clés reconnues (forme normalisée : casse et ponctuation ignorées) |
|---|---|
| identifiant | `id`, `client_id`, `uuid` — **toute chaîne qui est un uuid** dans une liste nue |
| licence | `licence`, `licences`, `licence_number`, `numero_licence`, `licence_propre`, `licence_propre_numero` |

Règles de résolution (identique à la recherche de licence du
`convert-to-blacklist`, §3.2 de `docs/convert_to_blacklist_api.md`) :

* **valeur numérique pure** (`5747508901`) → `licence_propre_numero`
  **et** `licence_number` ;
* **valeur textuelle** (`5747-5089-01`) → `licence_number` seulement ;
* dans un objet, la clé d'identifiant **prime** sur la clé de licence ;
* dans une liste nue, un uuid est un identifiant, tout le reste une licence ;
* corps non JSON, enveloppe inconnue, liste vide, cible vide ou non chaîne,
  lot trop long (> `PUBLIC_API_MAX_ITEMS`) → **`422`** (rien n'est écrit).

## 3. Ce qui est écrit (et par qui)

Pour **chaque client** désigné, dans **une transaction par cible** :

| Colonne | Valeur |
|---|---|
| `client_id` | le client visé |
| `comercial_id` | **l'employé configuré** — `public_api.no_reservations_comercial_email` |
| `status` | `NO` |
| `reservation_group_id` | **`NULL`** (une fausse réservation n'appartient à aucune liste d'un employé : « Mes listes » reste vide) |

* l'employé est résolu **par son adresse** (jamais par un identifiant figé :
  les uuid diffèrent d'une base à l'autre). Absent de la base → **`422`**
  avec le message qui le nomme, plutôt qu'un échec silencieux ;
* le stock n'est **pas** réparti entre les commerciaux : c'est le but de
  l'endpoint ;
* l'écriture passe par Eloquent, donc `Reservation::saved` →
  `Client::syncCurrentReservation()` : le pointeur
  `current_reservation_id` / `current_comercial_id` du client pointe sur la
  fausse réservation (exactement comme après une issue d'appel réelle) ;
* **une seule réservation `NO` par (client, employé)** : un `NO` d'un *autre*
  commercial ne bloque pas celui-ci.

## 4. Ce qui n'est **pas** fait (effets de bord métier)

| Effet du workflow réel (RULES §3) | Ici |
|---|---|
| note `NO` sur le client | **non** |
| `returned_at` = 3 mois plus tard | **non** |
| passage du client en `UNAVAILABLE` | **non** |
| auto-liste-noire (tous les employés actifs ont répondu NON, §3.4 cas 6) | **non** |
| invalidation des listes distinctes / rappels | **non** |
| appel téléphonique, `call_logs`, consumption de quota | **non** |

Le statut du client reste **strictement inchangé** (un client
`UNAVAILABLE` le reste, un client `RESERVED` aussi) : seule la table
`reservations` gagne une ligne.

## 5. Cibles ignorées (jamais des erreurs)

| Compteur | `reason` | Cas |
|---|---|---|
| `not_found` | `not_found` | aucune ligne client ne porte la cible |
| `already` | `already_no` | ce client a **déjà** une réservation `NO` de cet employé |
| `skipped` | `blacklisted` | le client est en liste noire (`is_blacklisted` ou `status = BLACKLISTED`) — un « NON » n'a pas de sens sur un client définitivement exclu |

Ces trois cas restent dans `ignored_items[]` avec leur `reason`, **sans**
`error`, et le lot continue : la campagne est **ré-exécutable**.

Seuls les items **inexploitables** (aucune clé d'identifiant ni de licence,
cible vide) sont comptés dans `failed` / `errors[]`.

## 6. Réponse — rapport consolidé

```jsonc
{
  "success": true,
  "data": {
    "received": 3,            // cibles reçues
    "processed": 3,           // ✅ SUCCÈS : cibles examinées sans erreur
    "matched": 2,             // lignes clients trouvées
    "created": 2,             // ✅ réservations NO écrites
    "already": 0,             // déjà un NO de cet employé
    "skipped": 0,             // ignorés (liste noire)
    "not_found": 1,           // cibles introuvables
    "failed": 0,              // ❌ ERREURS
    "comercial_email": "mohamed.khemir@apex-structures.tn",
    "truncated": false,       // mode « statut » : périmètre > plafond ?
    "next_after": null,       // mode « statut » : curseur du prochain appel

    "created_items": [
      {"index": 0, "type": "licence", "key": "5747508901",
       "client_id": "01a0f79c-…", "enterprise_name": "10095800 canada Inc.",
       "licence_number": "5747-5089-01", "reservation_id": "01a0fd20-…",
       "client_status": "UNAVAILABLE"}
    ],
    "ignored_items": [
      {"index": 1, "type": "licence", "key": "9999999999",
       "client_id": null, "enterprise_name": null, "licence_number": null,
       "reason": "not_found"}
    ],
    "errors": []
  }
}
```

| Compteur | Unité | Signification |
|---|---|---|
| `processed` | cibles | **succès** = `received − failed` |
| `matched` | lignes clients | lignes trouvées pour les cibles reçues |
| `created` | réservations | lignes `reservations` réellement écrites |
| `already` / `skipped` / `not_found` | lignes clients | sans effet (§5) |
| `failed` | cibles | **erreurs** → détaillées dans `errors[]` |

* une cible qui désigne plusieurs lignes (licence en doublon) compte 1 dans
  `processed` et N dans `matched` / `created` ;
* `success = false` **uniquement** si **aucune** cible n'a pu être examinée ;
* le SQL n'est jamais exposé : une exception est journalisée côté serveur
  (`Log::warning`) et résumée dans `errors[]`.

## 7. Exemples

Une licence :

```bash
curl -X POST https://HOST/api/v1/clients/create-no-reservations \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"licence": "5747508901"}'
```

```json
{"success":true,"data":{"received":1,"processed":1,"matched":1,"created":1,"already":0,"skipped":0,"not_found":0,"failed":0,"comercial_email":"mohamed.khemir@apex-structures.tn","truncated":false,"next_after":null,"created_items":[{"index":0,"type":"licence","key":"5747508901","client_id":"01a0f79c-…","enterprise_name":"10095800 canada Inc.","licence_number":"5747-5089-01","reservation_id":"01a0fd20-…","client_status":"UNAVAILABLE"}],"ignored_items":[],"errors":[]}}
```

Tout un périmètre (campagne « indisponibles »), paginé :

```bash
curl -X POST https://HOST/api/v1/clients/create-no-reservations \
  -H 'Content-Type: application/json' \
  -d '{"status": "UNAVAILABLE"}'
# {"success":true,"data":{"received":20000,…,"created":20000,…,"truncated":true,"next_after":"01a0fbc5-…"}}
# puis, jusqu'à "next_after": null :
curl -X POST https://HOST/api/v1/clients/create-no-reservations \
  -H 'Content-Type: application/json' \
  -d '{"status": "UNAVAILABLE", "after": "01a0fbc5-…"}'
```

## 8. Retrait de l'endpoint (prévu)

Le endpoint est **temporaire** : pour le retirer, supprimer dans cet ordre —

1. `backend/routes/api/shared.php` → la route `clients/create-no-reservations` ;
2. `backend/config/cors.php` → l'entrée `api/v1/clients/create-no-reservations` ;
3. `PublicClientController` → `createNoReservations()` + `noReservationItems()` ;
4. `Client` → `noReservationsFromItems()`, `noReservationsFromStatus()`,
   `noReservationsForTargets()`, `noReservationByTarget()`,
   `noReservationTargetFromItem()`, `NO_RESERVATION_*` ;
5. `backend/config/public_api.php` → `no_reservations_comercial_email`,
   `no_reservations_status_limit` (+ variables `.env` correspondantes) ;
6. `backend/tests/Feature/PublicClientNoReservationsTest.php` ;
7. `../blacklist/upload_no_reservations.py` +
   `../blacklist/generer_rapport_no_reservations.py` ;
8. `docs/RULES.md` §10 (ligne du tableau) et §12 (paragraphe), ce fichier.

Puis `php artisan route:clear` (ou `route:cache` en déploiement) pour
rejouer le cache de routes.

> **Rappel** : le retrait de l'endpoint **ne supprime pas** les fausses
> réservations déjà écrites. Si la campagne doit être annulée, les supprimer
> explicitement (par `comercial_id` et `status = 'NO'`), sinon elles restent
> dans l'historique des écrans.
