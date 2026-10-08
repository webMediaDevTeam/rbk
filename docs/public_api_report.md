# Rapport — API publique du projet RBK

> Rapport d'analyse (lecture seule). Complète `docs/public_api.md` (spécification
> des endpoints M2M) et `docs/RULES.md` §10.
> Base du code : `backend/routes/api/*.php`, `backend/app/Http/...`.

---

## 1. Périmètre & méthode

Toute l'API est préfixée `/api/v1` (`backend/routes/api.php:6-12`) et passe par
le middleware `log.http` (`LogHttpTraffic`). Cinq fichiers de routes sont chargés
dans un ordre **sémantique** (`shared` → `superAdmin` → `admin` → `entreprise` →
`commercial`) : les routes M2M et `clients/overview` doivent être déclarées
**avant** `clients/{id}`, sinon elles sont avalées par le paramètre de route
(commentaires `shared.php:31-32`, `:88-89`).

**Totaux : 91 déclarations de routes / 92 combinaisons verbe-URL**, dont 5
enregistrées **uniquement en `APP_ENV=local|testing`**.

Trois familles coexistent :

| Famille | Mécanisme | Nb |
|---|---|---|
| **M2M externe (n8n)** | en-tête `X-Api-Key` (`VerifyExternalSystemKey`) | 6 endpoints |
| **Auth publiques** | aucune auth | 8 routes |
| **API applicative** | Sanctum (`Bearer`) + `CheckRole` (+ `CheckPermission` sur 2 routes) | ~78 routes |

---

## 2. Inventaire par domaine

Légende : `S` = `auth:sanctum` · `CR:x` = `CheckRole` · `CP` = `CheckPermission` ·
`K` = `X-Api-Key`.

### 2.1 Authentification (publique) — `routes/api/shared.php:17-24`

| Verbe | Route | Méthode | Testé |
|---|---|---|---|
| POST | `auth/login` | `AuthController@login` | ✔ |
| POST | `auth/login/otp` · `auth/login/otp/verify` | `@sendLoginOtp` / `@verifyLoginOtp` | ✔ |
| POST | `auth/forgot-password` (+ `/verify`, `/reset`) | `@forgotPassword` … | ✔ |
| POST | `auth/verify-account` · `auth/resend-verification` | `@verifyAccount` / `@resendVerification` | ✔ |

### 2.2 M2M externe (clé) — `shared.php:33-64`

| Verbe | Route | Méthode | Statut |
|---|---|---|---|
| POST | `clients/bulk-upsert` | `PublicClientController@bulkUpsert` | pérenne |
| POST / DELETE | `clients/bulk-delete` | `@bulkDelete` | pérenne |
| POST | `clients/convert-to-blacklist` | `@convertToBlacklist` | **temporaire** |
| POST | `clients/convert-to-unavailable` | `@convertToUnavailable` | **temporaire** |
| POST | `clients/create-no-reservations` | `@createNoReservations` | **temporaire** |

### 2.3 Tous rôles authentifiés — `shared.php:67-93`

`auth/me`, `auth/logout`, `auth/profile/password/*` (OTP), `dashboard/stats`,
`filters`, `categories`, `municipalities`, `administrative-regions`, `sources`,
`clients/overview`.

### 2.4 RingCentral / call-logs — `shared.php:99-145` + `commercial.php:49-51`

* Admin : `call-logs/devices`, `call-logs/users*`, `call-logs/by-phone/{phone}`,
  `call-logs/employees/{id}/logs` (+ `/sync`), `call-logs/sync/employees`,
  `call-logs/recordings/{id}/content`.
* Super admin, **`local|testing` uniquement** : `call-logs/account`,
  `call-logs/call`, `call-logs/calls/{sessionId}` (GET / DELETE / recordings).
* Commercial : `call-logs/my-call`, `call-logs/client-calls/{callLog}` (+ content),
  `POST …/calls/{s}/parties/{p}/record` (commercial + admin).

### 2.5 Users / employés — `shared.php:151-168`

`users/username-available` (avant `users/{id}`), `users`, `users/{id}`,
`users/{id}/avatar`, `users/{id}/status`, `DELETE users/{id}`.

### 2.6 Gestion — `superAdmin.php`, `admin.php`, `entreprise.php`

`POST admins` (SA) · `liste-noire`, `liste-noire/{id}/debloquer` · `commercials`,
`commercials/clients` (+ `/{id}`, `/phone`, `/blacklist`), `commercials/{id}` ·
15 routes `enterprises`/`entreprises` (CRUD, `status`, `logo`, `stats`).

### 2.7 Prospects (COMERCIAL) — `commercial.php:14-58`

`clients`, `clients/mes`, `clients/{id}`, `clients/{id}/blacklist` (+ `CP`),
`clients/{clientId}/outcome`, `clients/{clientId}/notes`, `notes`,
`notes/{id}`, `reminders` (+ `count`, `{id}/done`), `reservation-groups`
(+ `{id}`), `clients/reserver`, `reservations/active-count`,
`reservations/release-pending` (+ `CP`).

---

## 3. Authentification & autorisations

* **Jeton** : Sanctum *personal access token*, renvoyé dans la clé **`jeton`**
  (`AuthController.php:50-57`, OTP `:216-223`). Frontend : `Authorization: Bearer`
  (`frontend/src/api/client.js:17`).
* **`config/sanctum.php:53` → `expiration = null` : les tokens n'expirent jamais.**
* **Rôles** : `SUPER_ADMIN(0) > ADMIN(1) > COMERCIAL(2)` (`UserController::ROLE_HIERARCHY`).
  `CheckRole` renvoie 401 `{"message":"Non authentifié."}` / 403
  `{"message":"Accès non autorisé."}`.
* **Privilège** : `users.has_permission` (« Privilège de libération ») exigé par
  `CheckPermission` sur `POST clients/{id}/blacklist` et
  `POST reservations/release-pending` (403 explicite).
* **Clé M2M** : `VerifyExternalSystemKey` compare `X-Api-Key` en temps constant
  (`hash_equals`) à `config('services.external_system.key')`, **échec fermé**
  (401 si le serveur n'a pas de clé configurée).

---

## 4. Conventions

| Sujet | Convention | Exception |
|---|---|---|
| Enveloppe | `{success: true, data}` ; erreurs `{success:false, message|error}` | **Auth** : `{message, utilisateur, profil, jeton}` sans `success` ; **`GET liste-noire`** : `{"clients":[...]}` brut ; **`release-pending`** : clés au top-level |
| Pagination | `data.pagination = {current_page, last_page, per_page, total}`, `per_page` ≤ **300** | RingCentral ≤ **250** ; `liste-noire` **non paginée** |
| Filtres clients | `search`, `municipality`, `category`, `administrative_region`, `sort_by`/`sort_order` (whitelist), `status` + `reservation_status` (badges) | — |
| Validation | `$request->validate()` + messages FR | import M2M : **normalisation** (`scrubAttributes`) au lieu de validation |
| 502 | dégradation RingCentral : `{success:false, error, upstream}` | — |
| Rate limiting | **aucun** (`throttleApi()` jamais appelé) | — |
| CORS | `allowed_origins = ['*']` + `supports_credentials = true` | variable `CORS_ALLOWED_ORIGINS` **jamais lue** |

---

## 5. Couverture de tests

386 tests `public function test` au total dans `backend/tests` (SQLite
in-memory, garanti par `backend/tests/bootstrap.php`).

Surface M2M / publique :

| Fichier | Tests |
|---|---|
| `PublicClientConvertToBlacklistTest` | 23 |
| `PublicClientNoReservationsTest` | 16 |
| `PublicClientDataCleanupTest` | 15 |
| `PublicClientConvertToUnavailableTest` | 15 |
| `PublicClientBulkUpsertTest` | 10 |
| `PublicClientBulkDeleteTest` | 10 |
| `ClientBulkUnavailableFromPhoneTest` | 11 |
| `VerifyExternalSystemKeyTest` | 6 |
| `ClientManuallyUpdatedTest` | 5 |

API applicative (ordres de grandeur) : `ReservationWorkflowApiTest` 29,
`CallWorkflowServiceTest` 25, `RingCentralApiTest` 18, `EmployeeCallLogsTest` 14,
`OutcomeControllerTest` 12, `NoteControllerTest` 11, `ReminderControllerTest` 11,
`ReservationGroupControllerTest` 11, `CommercialEmployeeCallTest` 11,
`LoginByUsernameTest` 11, `UsernameFieldTest` 10, `ReleasePermissionTest` 9,
`DoubleInfoStatusTest` 13, `CurrentReservationStatusBadgesTest` 7.

**Routes sans aucun test** : `GET dashboard/stats`, `POST auth/logout`,
`GET auth/me` (succès), `GET clients/mes`, `GET categories|municipalities|
administrative-regions`, `GET users`, `GET users/{id}`, `POST users/{id}/avatar`,
`PATCH|DELETE users/{id}`, `GET liste-noire` (succès admin), `GET commercials`,
`POST commercials/clients/{id}/blacklist`, `GET entreprises/{id}` (succès),
`POST entreprises/{id}/logo`.

---

## 6. Écarts documentation ↔ code

1. **`PAYLOAD_MAP`** : la doc cite `Client::PAYLOAD_MAP` ; la constante est en
   `ClientImportService::PAYLOAD_MAP` (`:44`). Contenu du tableau exact ✔.
2. **Méthodes d'import** attribuées à `Client` alors qu'elles vivent dans
   `ClientImportService` (`bulkUpsertFromScraperPayload`, `convertToBlacklist*`,
   `noReservations*`…) — erreur répétée dans `public_api.md`, `RULES.md`,
   `TODOS.md` et les specs satellites.
3. **CORS** : `public_api.md:325` promet `CORS_ALLOWED_ORIGINS` ; la clé n'existe
   pas dans `config/public_api.php` et `config/cors.php:42` ne lit jamais cette
   variable (les valeurs injectées par `docker-compose*.yml` sont donc sans
   effet). `supports_credentials=true` avec `origin: *` n'est pas documenté.
4. **`log.http`** (appliqué à **toute** l'API), l'absence de rate limiting et la
   forme exacte des 401/403 (`{message}` sans `success`) ne sont pas documentés.
5. **`docs/external_api.md` est obsolète / hors-sujet** : snippet de service
   RingCentral sans route `/api/v1` ni middleware ; cite un contrôleur
   `app/Http/Controllers/CallLogController.php` **inexistant** (réel sous
   `Api/V1/Shared/`) ; renvoie des tableaux bruts alors que l'API enveloppe et
   renvoie 502 ; `perPage = 100` vs 250 réel ; contient un
   `RINGCENTRAL_CLIENT_ID` en clair.
6. **Rôles RingCentral** dans `RULES.md:1283-1302` incomplets : `call-logs/users*`
   et `by-phone` sont `ADMIN,SUPER_ADMIN` (pas seulement `auth:sanctum`) ;
   `call-logs/devices` est toujours enregistré (hors bloc local) ;
   `POST …/record` accepte aussi `COMERCIAL`.
7. **Postman** : 10 requêtes (bulk-upsert ×5, bulk-delete ×5) → **2 endpoints
   publics sur 5** ; aucun secret commité (clé vide) ✔ ; les 3 temporaires sont
   explicitement hors collection (documenté ✔).
8. **12 familles de routes ne figurent dans aucune doc** : `auth/*`, `users*`,
   `admins`, `liste-noire`, `commercials*`, aliases `entreprises*`, `clients/mes`,
   `reminders*`, `reservation-groups*`, `notes`, `outcome`, `reserver`,
   `release-pending`, `active-count`. Pas d'OpenAPI/Swagger.

---

## 7. Risques (priorisés)

| # | Risque | Preuve | Action suggérée |
|---|---|---|---|
| 1 | **`LogHttpTraffic` journalise les corps de requête/réponse sur toute l'API** → mots de passe en clair, codes OTP, `password_reset_token`, jeton Bearer atterrissent dans `storage/logs/laravel.log` | `LogHttpTraffic.php:22-28, 45-52` | masquer les champs sensibles (allow-list) |
| 2 | **Aucun rate limiting** sur 8 routes publiques + 6 endpoints M2M ; une seule clé statique partagée, pas de rotation, pas de quota | `bootstrap/app.php:23-35` | `RateLimiter::for('api')` + rotation de clé |
| 3 | **Tokens Sanctum à durée illimitée**, logout = token courant seul | `config/sanctum.php:53` | expiration + révocation |
| 4 | **CORS `*` + credentials** | `config/cors.php:42,52` | allow-list lue depuis l'env (et corriger la doc) |
| 5 | `GET liste-noire` : tableau **entier**, non paginé, `Client::toArray()` (téléphone, courriel compris) | `AdminController.php:15-22` | pagination + masque de téléphone |
| 6 | 502 RingCentral renvoie le **corps amont complet** + message d'exception SDK | `RingCentralController.php:1043-1062` | ne garder qu'un code + message générique |
| 7 | **3 endpoints M2M temporaires** dont `create-no-reservations`, qui fabrique des réservations `NO` attribuées à un employé réel (e-mail par défaut **codé en dur** dans `config/public_api.php:31-34` **et** `ClientImportService::NO_RESERVATIONS_COMERCIAL_EMAIL:1176`) | idem | date de retrait + configuration unique |
| 8 | Fragilité d'ordre de routes (aucun test de garde) | `shared.php:31-32, 88-89` | test « l'ordre des routes protège les chemins statiques » |
| 9 | `findOrFail` sans message maison → 404 révélant le FQCN du modèle | `AdminController.php:32`, `OutcomeController.php:31` | messages 404 uniformes |
| 10 | **Routes orphelines** (aucun consommateur frontend, ni test d'usage) : `GET clients/mes`, `GET categories|municipalities|administrative-regions`, tout le bloc SUPER_ADMIN `local|testing` (`call-logs/account`, `call-logs/call`, `call-logs/calls/{s}`…) — la page `/call-logs-test` qui les consommait vient d'être **supprimée** | grep négatif `frontend/src` | décider : retirer ou documenter |

---

## 8. Synthèse

* L'API est **large** (91 routes) et **globalement bien testée sur ses bords
  sensibles** (M2M : 96 tests ; workflow métier : 40+).
* Sa faiblesse n'est pas fonctionnelle mais **documentaire et opérationnelle** :
  pas de spec unique (OpenAPI absent), 12 familles de routes non documentées,
  deux docs obsolètes (`external_api.md`, `frontend_structure.md`), et des
  écarts constants entre `RULES.md` et le code.
* Les risques les plus concrets sont **sécuritaires** : logs contenant des
  secrets, aucun quota, tokens immortels, CORS ouvert. Aucun n'exige de refonte
  — ce sont des réglages ciblés.

---

*Rapport généré le 2026-10-08 (code `deabac5`).*
