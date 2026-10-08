# TODOS — Refonte workflow réservation / appel

Consolidation de `UDAPTE.md` + `permission_and_rules.md` (ces deux fichiers ont
été supprimés, leurs règles vivent dans `docs/RULES.md`).

## Phase 1 — Migrations & base de données ✅

- [x] `clients` : `blocked_until` → **`returned_at`** (compte à rebours admin :
      3 mois NON / 21 jours BV-Injoignable).
- [x] `clients.status` : valeurs `AVAILABLE`, `RESERVED`, `SUCCESS`,
      `UNAVAILABLE_TEMP`, `BLACKLISTED` (migration des anciennes valeurs).
- [x] `reservations` : compteurs `bv_count`, `injoinable_count` (alimentés depuis
      l'historique d'appel), colonne **`expires_at` supprimée**.
- [x] `reservations.status` : `EN_ATTENT`, `OUI`, `NON`, `BV`, `INJOINABLE`
      (INJOINABLE affiché « à rappeler »).
- [x] `call_outcomes.outcome` : `BOITE_VOCALE` → `BV` (renommé partout).
- [x] `reservation_groups.name` éditable.
- [x] Migrations 1/2/3 exécutées et vérifiées sur MySQL (150 clients, 69 résa).

## Phase 2 — Modèles & services ✅

- [x] `Client` : `returned_at` fillable/cast, `scopeAvailable()` (tient compte de
      `returned_at` passé).
- [x] `Reservation` : `bv_count`, `injoinable_count`, `recall_at` +
      `scopeActive()` (remplace l'ancien `pendingFor` supprimé).
- [x] `Note` / `CallOutcome` : **8 mots max** via trait `LimitsNoteWords`.
- [x] `CallWorkflowService` : OUI/NON/BV/INJOINABLE/BLACKLIST, rappel auto 3 j,
      seuil 2 tentatives → 21 j, auto-blacklist après NON (avec journalisation
      `CallOutcome` BLACKLIST), `handleRecallExpired` sans suppression de réservation.

## Phase 3 — Cron ✅

- [x] `ProcessTimeouts` (`clients:process-timeouts`, 10 min) : uniquement les
      rappels expirés, via le service ; réservations conservées.
- [x] `ProcessClientReactivation` (`clients:reactivate`, horaire) : recréé,
      `returned_at` passé → `AVAILABLE`.
- [x] Planning mis à jour dans `bootstrap/app.php`.
- [x] Tests manuels (5 cas) exécutés puis nettoyés.

## Phase 4 — API & UI ✅

### Backend
- [x] `ReservationController` : `activeCount`, `store` sans durée/expiration,
      candidats via `available()` + exclusion réservations actives et issues
      NON/BV propres ; `pendingCount` supprimé, **`releasePending` réintroduit**
      (§ Phase 9 — libération de toute la liste, pas d'un groupe).
- [x] `OutcomeController` : délègue au service, validation
      `in:OUI,NON,BV,INJOINABLE`, note nullable, **`recall_at` requis pour
      `INJOINABLE`** (datetime dans le futur) ; `release` supprimé.
- [x] `ReservationGroupController` : `show` sans `expires_at` (+ statut/compteurs/
      `recall_at`), `releasePending` supprimé, nouveau `update` (rename).
- [x] `ReminderController` : filtre par **type** `?type=INJOINABLE` (défaut,
      page « Rappels ») ou `BV` (page « Auto-rappels ») + `recall_at` non nul ;
      `reminders/count?type=` séparé.
- [x] `ClientController` : blacklist via service, note nullable, `returned_at`,
      recherche étendue (entreprise, répondants, **catégorie**), filtres
      `date_from`/`date_to` **supprimés**.
- [x] `CommercialAdminController` : `unblock` supprimé, blacklist via service,
      `returned_at` dans les payloads, recherche étendue idem (catégorie),
      filtres de date supprimés.
- [x] `AdminController::debloquerClient` : `returned_at` réinitialisé.
- [x] Routes : release/pending/unblock retirés, `PATCH reservation-groups/{id}` +
      `GET reservations/active-count` ajoutés (vérifiés via `route:list`).

### Frontend
- [x] API : `release*`/`pending*` supprimés, unblock → `liste-noire/{id}/debloquer`,
      `updateReservationGroupApi` (PATCH), `activeReservationsCountApi`.
- [x] `ReservationModal` : portail `{count}` seul (**sans nom de liste** :
      champ retiré), plus de gate « en attente » ni de bouton libération,
      conflits affichés `reservation_status`.
- [x] `ReservationModal` : compteurs **200 / 250 / 300** uniquement, champ
      libre supprimé (côté FE `COUNT_OPTIONS` et BE `in:200,250,300`)
      → **50 / 80 / 100 / 120** en Phase 9.
- [x] `MesListes` : colonne « En attente » et boutons release supprimés.
- [x] `GroupDetail` : rename inline, colonne « Expire le » remplacée par le badge
      de réservation, lignes `recall_at` masquées (+ compteur « rappel(s) masqué »),
      *trail row* sur les lignes BV/INJOINABLE.
- [x] `ActionModal` : issues OUI/NON/BV/INJOINABLE, note optionnelle (8 mots max),
      encart « Rappel automatique sous 3 jours » pour **BV** ;
      **rappel `INJOINABLE` en `datetime-local`** (obligatoire, dans le futur,
      pas de select minute/mois) envoyé comme `recall_at` ISO.
- [x] **Deux pages de rappels** : « Rappels » = `INJOINABLE` seul ;
      nouvelle page **« Auto-rappels »** (`/auto-rappels`) = `BV` seuls
      (route, entrée sidebar `Voicemail`, badge dédié ; `useReminders(type)`).
- [x] Bouton **« Suite appel »** masqué si le client n'est pas réservé par
      l'utilisateur connecté (`ClientDetail/index.jsx` + `my_reservation` BE
      limité aux réservations **actives** du connecté, client `RESERVED`/`SUCCESS`).
- [x] Libellés **« Commercial(s) » → « Employé(s) »** partout où c'est visible
      (sidebar, breadcrumbs, titres, tableau/cartes, modals, toasts, dashboard,
      placeholders, badge rôle profil/header, note d'auto-blacklist). Les
      identifiants de code (`/commerciaux`, `Commercial*Api`, rôle `COMERCIAL`)
      sont inchangés.
- [x] `useNoteTimeline` : `BV` (affiche « À rappeler » pour INJOINABLE),
      unités SEMAINE/MOIS retirées des maps d'affichage.
- [x] Badges statut : nouveaux statuts client + `ReservationStatusBadge` créé.
- [x] `ClientsHistoryToolbar` : statuts recalibrés, filtres « Du / Au »
      **supprimés**.
- [x] `ProspectToolbar` : recherche multi-critères F-22, filtres « Du / Au »
      **supprimés**.
- [x] `ProspectTable` : colonne « Retour » (compte à rebours admin, format concis).
- [x] `ProspectTable` / `ProspectCard` : colonnes **N°, Entreprise, Répondants,
      N° de licence, NEQ, Catégorie** (commun aux pages `/prospects` et
      `/clients-historique`), formatage partagé `prospectFormat.js`.
- [x] Recherche F-22 étendue à **toutes les colonnes affichées** (entreprise,
      NEQ, licence, licence propre, répondants, catégories + autorisées),
      côté commercial **et** admin.
- [x] Pagination : options **50 / 100 / 200 / 300** (défaut 50 partout),
      plafond serveur `per_page` porté à **300**.
- [x] `Sidebar` : badge « Mes listes » = réservations actives (F-18).
- [x] `Mes listes` : tableau enrichi — `Clients`, `Demandé`, `Traités`,
      `OUI`, `NON`, `BV`, `Injoinable`, `Restant`, `Employé` (jointure
      `users`) et `Créé le`, tous calculés par `GET reservation-groups`.
- [x] Blacklist : note optionnelle, label « 8 mots max ».
- [x] Build frontend vérifié (`vite build` ✅).

## Phase 5 — Docs & tests 🔄

- [x] `docs/RULES.md` : règle métier unique (fusion des 2 specs).
- [x] `docs/TODOS.md` : ce fichier.
- [x] Suppression de `docs/permission_and_rules.md` et `docs/UDAPTE.md`.
- [x] Tests automatisés — priorité `CallWorkflowService` (issues, rappel 3 j,
      seuil 2 tentatives, rappel expiré sans suppression, auto-blacklist,
      limite 8 mots, réactivation, endpoints d'unblock/rename).

## Phase 6 — Intégration n8n (données de licence) 🔄

- [x] Migration `2026_09_24_000004` : `licence_propre_numero` (INT UNSIGNED
      UNIQUE) + `cautionnement_compagnie` (JSON) **ajoutés** aux colonnes
      existantes — aucune colonne renommée, PK `id` UUID et booléen
      `licence_propre` conservés.
- [x] Modèle `Client` : `fillable` + casts (`integer`, `array`).
- [x] Contrôleurs : bloc licence complet renvoyé par `GET clients/{id}`
      (commercial) **et** `GET commercials/clients/{id}` (admin), +
      `licence_propre_numero` dans la recherche F-22.
- [x] Vue React `ClientDetailsTab` : « Licence (propre) n° », libellés exacts
      (Date de début / délivrance, Date de fin / paiement annuel, Montant de la
      caution), liste des cautionnements, catégories, répondants normalisés
      (chaîne ou `{name, role}`).
- [x] Tests : `ClientLicenceFieldsApiTest` (5 tests) — 65 tests au total.
- [x] **Webhook n8n / scraper** : `POST /api/v1/clients/bulk-upsert`
      (`PublicClientController`) **livré** — enveloppe `{"clients": [...]}` ou
      liste JSON nue, `Client::bulkUpsertFromScraperPayload()` (transaction par
      ligne, clé `licence_number` + repli `licence_propre_numero`), réponse
      `{success, data:{received, processed, created, updated, unchanged,
      failed, errors[]}}`, lot borné par `PUBLIC_API_MAX_ITEMS` (1000),
      CORS (`config/cors.php`) + `config/public_api.php`, tests
      `PublicClientBulkUpsertTest` (10 tests). **Sans authentification** et
      sans validation NOT NULL côté webhook : choix confirmé (spec
      `docs/public_api.md`), cf. RULES §12.
- [x] **Suppression en masse** : `POST|DELETE /api/v1/clients/bulk-delete`
      (même contrôleur) — corps `{"licences": [...]}` / `{"clients": [...]}` /
      liste JSON nue, `Client::bulkDeleteFromScraper()` (transaction par
      ligne). **Un client avec des données liées (`reservations`, `notes`,
      `rappels`) est ignoré** : `skipped` + `skipped_items[{index,
      licence_number, linked:{…}}]` et la boucle passe au client suivant ;
      absent → `missing_items[]`. Tests `PublicClientBulkDeleteTest`
      (10 tests).
- [x] **Conversion en liste noire par nom** (endpoint public **temporaire**) :
      `POST /api/v1/clients/convert-to-blacklist` (`PublicClientController`)
      — corps `{"name": "…"}` / `{"names": [...]}` / `{"clients": [...]}` /
      liste JSON nue, `Client::convertToBlacklistFromName()` (transaction par
      nom). Recherche `LOWER(enterprise_name) = LOWER(?)` **OU**
      `LOWER(name) = LOWER(?)` (insensible à la casse, toutes les lignes
      converties) ; geste métier §3.4 (`is_blacklisted`, `status =
      BLACKLISTED`, `returned_at` vidé, rappels annulés, note `BLACKLISTED`
      par `SYSTEM`, réservations conservées). Réponse `{success, data:
      {received, processed, matched, zapped, ignored, not_found,
      already_blacklisted, failed, zapped_items[], ignored_items[],
      errors[]}}` — **rapport** : `processed` (succès) / `zapped` / `ignored`
      / `failed` (erreurs) ; **un nom introuvable est ignoré, pas une
      erreur**, lot ré-exécutable (idempotent).
      Tests `PublicClientConvertToBlacklistTest` (15 tests).
      **À retirer** : procédure §7 de `docs/convert_to_blacklist_api.md`.

## Phase 6bis — Intégration RingCentral (Call Logs) 📞

- [x] Dépendance SDK : `ringcentral/ringcentral-php` (^3.0).
- [x] Config & Env : `config/services.php` + `backend/.env.example`.
- [x] Service : `App\Services\RingCentralService` (getAllUsers, getCallHistoryByUser, getCallHistoryToNumber).
- [x] Contrôleur : `CallLogController` (`/api/v1/call-logs/*`) avec gestion d'erreurs HTTP 502.
- [x] Tests : `CallLogApiTest` (5 tests validés).
- [x] Vue de test Super Admin : `/call-logs-test` dans la navigation Super Admin.
- [x] **Contrôle d'appel (pass-through, SUPER_ADMIN, sans écriture en base)**
      — `RingCentralController` + méthodes `RingCentralService::getAccount`,
      `getDevices`, `getPhoneNumbers`, `makeCallOut`, `getCallSession`,
      `startRecording`, `getRecordings`, `hangUpSession` :
      * `GET /call-logs/account` — compte / entreprise (`account_id`) ;
      * `GET /call-logs/devices` — appareils (source du « from »), enrichis
        de `phoneNumbers` / `phoneNumber` (`GET /account/~/phone-number`
        rattaché par `extension.id` — les softphones ont `phoneLines: []`),
        pour afficher **le numéro** (et non le nom) dans la sélection
        « Appareil source » ;
      * `POST /call-logs/call` — `to` + (`device_id` | `from` | `user_id`),
        retourne `session_id` / `party_id` ; `user_id` résout l'extension
        (correspondance d'e-mail) puis son appareil ;
      * `GET /call-logs/calls/{sessionId}` — statut + `parties` ;
      * `POST /call-logs/calls/{sessionId}/parties/{partyId}/record` ;
      * `GET /call-logs/calls/{sessionId}/parties/{partyId}/recordings` ;
      * `DELETE /call-logs/calls/{sessionId}` — raccroché.
- [x] Tests : `RingCentralApiTest` (17 tests validés — RBAC 401/403,
      validation 422, résolution `user_id`, numéros des appareils, 502 sur
      panne du service).
- [x] Vue de test : sections 4 à 6 de `/call-logs-test` (compte, appareils +
      appel sortant, statut / enregistrement / raccroché), entrée de nav
      réactivée.

### ✅ Source d'appel de l'employé (création / édition Admin-Super Admin)

- [x] **1. Migration** : `employees.ringcentral_device_id` (appareil choisi)
      + `employees.ringcentral_from_number` (numéro affiché, figé) — nullable
      (`2026_10_06_000001_add_ringcentral_device_to_employees_table`).
- [x] **2. Backend** : validation + persistance dans `UserController::store`
      (branche COMERCIAL) et `update`, affichage
      `profil.ringcentral_device_id` / `profil.ringcentral_from_number`.
- [x] **3. Route** : `GET /call-logs/devices` ouverte à
      `ADMIN,SUPER_ADMIN` (les autres routes RingCentral restent SUPER_ADMIN).
- [x] **4. Frontend** : select « Appareil / numéro source » (libellé = **le
      numéro**) dans `CommercialCreateModal` + `CommercialUpdateModal`,
      payload `ringcentral_device_id` + `ringcentral_from_number`,
      préremplissage à l'édition (libellés partagés :
      `frontend/src/utils/ringcentral.js`).
- [x] **5. Tests + vérification live** : `EmployeRingCentralDeviceTest`
      (6 tests — création, mise à jour, retrait, validation, RBAC, sans
      source) + parcours réel create/update/delete vérifié contre l'API et
      la table `employees`.

### ✅ Identifiants RingCentral par entreprise

Chaque entreprise peut brancher **son propre compte** RingCentral (application
OAuth + jeton) ; un champ vide retombe sur `.env`.

- [x] **1. Migration** : `enterprises.ringcentral_client_id`,
      `enterprises.ringcentral_client_secret`, `enterprises.ringcentral_token`
      + `enterprises.source` (colonne **générique**, hors RingCentral — voir
      « Répertoire des sources » ci-dessous) —
      `2026_10_08_120000_add_ringcentral_credentials_to_enterprises_table`
      (`php artisan migrate`, aucune donnée supprimée).
- [x] **2. Modèle** : `Enterprise::$fillable` + `getRingCentralCredentials()`
      (repli **champ par champ** sur `config('services.ringcentral.*')`) +
      `hasOwnRingCentralAccount()`.
- [x] **3. Service** : `RingCentralService::configure()` reconfigure la
      requête en cours (SDK + jeton d'authentification) ; les clés de cache
      sont dérivées des identifiants (`scoped()`) pour qu'aucun compte ne
      partage le jeton `ringcentral:auth` ni les listes figées.
- [x] **4. API entreprise** : règles `RINGCENTRAL_RULES` + `SOURCE_RULES` sur
      `store` / `update` (champ **absent** = conservé, `''` = retiré, valeur
      = remplacé, `trim` systématique) ; `formatEnterprise()` renvoie
      `ringcentral_client_id` / `source` et seulement
      `ringcentral_client_secret_set` / `ringcentral_token_set` — le secret
      et le jeton ne quittent jamais l'API (ADMIN + SUPER_ADMIN).
- [x] **5. Frontend** : 3 champs optionnels (Client ID, Client Secret,
      Token) dans la section « RingCentral » d'`EnterpriseCreateModal` +
      `EnterpriseUpdateModal` (« laisser vide pour conserver »).
- [x] **6. Modales employé** : `GET /call-logs/devices?enterprise_id=` — la
      sélection « Appareil / numéro source » liste les appareils **du compte
      de l'entreprise choisie** (`queryKey` + `enabled` suivent
      `form.enterprise_id`, changement d'entreprise = source remise à zéro ;
      sans entreprise → aucun appel, pas de compte `.env` fantôme).
- [x] **7. Tests** : `EnterpriseRingCentralCredentialsTest` (9 tests —
      création, conservation / remplacement / effacement, validation, repli
      `.env`, `devices` avec / sans / entreprise inconnue, RBAC).

### ✅ Répertoire des sources (sélecteur « Source » des entreprises)

Table `sources` **sans CRUD** : une seule route de lecture.

- [x] **1. Migration** : `sources` (`id` uuid, `name` unique, `timestamps`) —
      `2026_10_08_130000_create_sources_table` (`php artisan migrate`).
- [x] **2. Seed** : `SourceSeeder` → **« Affaire »**, **« Angalis »**
      (`firstOrCreate`, idempotent ; appelé par `DatabaseSeeder` et à la
      main via `php artisan db:seed --class=SourceSeeder`).
- [x] **3. API lecture seule** : `SourceController::index` +
      `GET /api/v1/sources` (groupe `auth:sanctum`, tous rôles) →
      `{success, data: [{id, name}]}` trié. **Aucune route** `POST` /
      `PUT` / `DELETE` n'existe pour ce modèle.
- [x] **4. Frontend** : `listSourcesApi()` + sélecteur « Source » dans
      `EnterpriseCreateModal` / `EnterpriseUpdateModal` — la valeur déjà
      enregistrée est **reprise dans les options** même si elle a disparu du
      répertoire ; le champ est sorti de la section RingCentral (« source »
      n'est pas un attribut RingCentral).
- [x] **5. Tests** : `SourceListTest` (5 tests — liste triée pour tout
      utilisateur authentifié, 401 sans jeton, seeder idempotent, **aucune
      route CRUD** (405 / 404), enregistrement de la source sur l'entreprise
      y compris conservation si le champ est omis).

> **Suite éventuelle** : `POST /call-logs/call` et `POST /call-logs/sync/
> employees` utilisent encore le compte `.env` — à reconfigurer avec
> l'entreprise de l'appelant si un compte par entreprise doit aussi passer
> les appels et synchroniser les postes.

### ✅ Source des prospects (`clients.source`, défaut « Affaire »)

Même répertoire `sources` que les entreprises (§13), **sans rapport avec
RingCentral** et **sans aucune saisie UI** : la valeur arrive du payload n8n
et se lit dans les réponses / la fiche client.

- [x] **1. Migration** : `clients.source` — `string(255) NOT NULL DEFAULT
      'Affaire'` (`2026_10_08_140000_add_source_to_clients_table`,
      `php artisan migrate` uniquement) : les ~53 000 prospects déjà en base
      passent en « Affaire » (0 ligne sans source).
- [x] **2. Modèle** : `Client::DEFAULT_SOURCE = 'Affaire'` + `source`
      ajouté à `$fillable`.
- [x] **3. Import n8n** : clé `Source` ajoutée à `Client::
      PAYLOAD_MAP` — valeur non vide nettoyée (`cleanLabel()`), valeur vide
      ou absente **retirée des attributs** : création → défaut « Affaire »,
      mise à jour → valeur en place **conservée** (jamais de `NULL`, jamais
      de remise à zéro lors d'une resync sans la clé).
- [x] **4. Exposition** : `source` ajouté à `ClientController::
      formatClient()` (liste + détail commercial) et aux deux formateurs de
      `CommercialAdminController` (liste + détail admin) ; affiché en
      lecture seule (« Source ») dans l'onglet **Détails** de la fiche
      client (`ClientDetailsTab`).
- [x] **5. Tests** : `ClientSourceTest` (6 tests — défaut à la création
      via le modèle et via l'import, valeur du payload, clé vide sans
      `NULL`, conservation / remise à jour, exposition listes + détail
      admin) ; doc `RULES.md` §12 et `public_api.md` §2.3 mises à jour.

### ✅ Appel direct du client — bouton « Appeler » (COMERCIAL)

Bouton « Appeler » sur les lignes / cartes des pages **Mes listes**
(`/mes-listes/:id`), **Rappels** (`/reminders`) et **BV** (`/auto-rappels`,
réutilise la page Rappels) : `to` = numéro du client, `from` = numéro de
l'employé connecté.

- [x] **1. Backend** : `POST /api/v1/call-logs/my-call`
      (`RingCentralController::callAsEmployee`, route du groupe COMERCIAL de
      `routes/api/commercial.php`) — le navigateur n'envoie que `to` ;
      `from` (+ `device_id`) est **résolu côté API** dans
      `employees.ringcentral_from_number` de l'utilisateur connecté →
      impossible d'emprunter le numéro d'un collègue ; 422 explicite si la
      fiche n'a pas de numéro source.
- [x] **2. Profil** : `AuthController::getProfile()` expose aussi
      `ringcentral_device_id` / `ringcentral_from_number`.
- [x] **3. Frontend** : `callMyNumberApi()` (`api/commercial.api.js`) +
      hook `useDirectCall()` (`hooks/use-direct-call.js`, toasts succès /
      erreur) + composant partagé
      `pages/shared/components/CallButton/index.jsx` branché dans
      `GroupDetail.jsx` (tableau + cartes), `ReminderTable.jsx` et
      `ReminderCard.jsx`.
- [x] **4. Tests** : `CommercialEmployeeCallTest` (7 tests — appel avec le
      propre numéro de l'employé, 422 sans source, `to` requis, 403 rôles,
      401, 502) — 30 tests RingCentral verts au total.
- [x] **5. Vérification live** (API de dev) : 422 sans source et 403
      (SUPER_ADMIN sur `my-call`) confirmés ; deux call-out réels avec une
      destination **non routable** (`+99999999999` → aucune communication
      établie, sessions terminées/raccrochées) : RingCentral **accepte**
      `from.phoneNumber` même s'il n'appartient pas à l'extension de session
      (pas de `MSG-304` / `CMN-101`), mais la 1ʳᵉ jambe (le poste qui
      décroche pour émettre) est toujours **l'extension de la session JWT**
      (`217943024` / `+15146005994`). ⚠️ l'appel part donc de la ligne
      authentifiée avec le numéro employé envoyé en source : pour que
      l'appel parte vraiment de *sa* ligne, le numéro doit être rattaché à
      cette extension (« forwarding number »), sinon il faudra un JWT par
      employé (todo « Sync Users » ci-dessous). Le CLID présenté au
      destinataire final n'a **pas** pu être vérifié (aucun appel
      complété).

### 📋 Onglet « Appels » — fiche employé (ADMIN / SUPER_ADMIN)

Nouvel onglet **« Appels »** de `/comercialDetail/:id` : journal d'appels
RingCentral de l'employé, résolu **via son `ringcentral_device_id`** (repli
: correspondance d'e-mail), avec lecture de l'**enregistrement quand il
existe**.

- [x] **1. Bugfix enregistrement** : `RingCentralService::startRecording()`
      poste un corps `[]` → le SDK le transmet tel quel à Guzzle
      (`Utils::streamFor([])`) et plante : `Invalid resource type: array`
      (constaté live sur `POST …/parties/{partyId}/record` → 502 ; **tout
      POST sans corps** est concerné). Poster sans corps (`null`) ✅ fait.
- [x] **2. Backend — journaux** : `GET /api/v1/call-logs/employees/{id}/logs`
      (`RingCentralController::employeeLogs`, route `ADMIN,SUPER_ADMIN`) :
      **lecture locale** depuis `call_logs` (aucun appel RingCentral à
      l'ouverture) — la récupération chez RingCentral passe par
      `POST …/logs/sync` (résolution via `ringcentral_device_id` → appareil
      → e-mail, call log `view=Detailed`) ✅ fait (voir « Passage au
      réel » ci-dessous).
- [x] **3. Backend — lecture d'un enregistrement** :
      `GET /api/v1/call-logs/recordings/{recordingId}/content`
      (`RingCentralController::recordingContent` + service
      `RingCentralService::getRecordingContent`) : métadonnées →
      `contentUri` → **proxy du flux audio** (le `contentUri` exige
      l'en-tête `Authorization`, qu'un `<audio>` ne peut pas envoyer) ✅ fait.
- [x] **4. Frontend** : onglet « Appels » (masqué hors ADMIN/SUPER_ADMIN) +
      `CallLogsCard` / `useCallLogs` (table date · sens · numéro · durée ·
      résultat · enregistrement, états chargement / vide / erreur) +
      `RecordingPlayer` (fetch blob → `<audio>`) ✅ fait.
- [x] **5. Tests + build** : `EmployeeCallLogsTest` (**12 tests verts**,
      RBAC 401/403, lecture locale sans API, synchro 422/502,
      proxy 200/502/422) ; suite complète
      **253 passed / 2 failed** (2 échecs préexistants non liés) ;
      `vite build` vert ✅ fait.
- [ ] **6. Vérification live** : **par vos soins** (aucun appel réel de mon
      côté — test manuel de l'onglet et d'un enregistrement existant).

#### ✅ Quota RingCentral (`429 CMN-301`) + doc `docs/ringcentral.md`

Le premier essai live de l'onglet est tombé sur
`RingCentral 429 Too Many Requests : CMN-301 — Request rate exceeded`.

- [x] **Jeton partagé entre requêtes** : `RingCentralService::authenticate()`
      restaure / re-persiste le jeton dans le cache (`ringcentral:auth`,
      TTL = durée résiduelle − 60 s) — avant, **chaque requête PHP** faisait
      un échange `/oauth/token` en plus des appels API.
- [x] **Listes cachées 60 s** : `getAllUsers`, `getDevices`,
      `getPhoneNumbers` (`Cache::remember`) — ouvertures répétées de
      l'onglet / des modales sans rejouer les mêmes requêtes.
- [x] **Message 429 explicite** (`RingCentralController::failed()`) :
      « Limite de requêtes RingCentral atteinte (429 CMN-301) : patientez
      une minute, la page réessaie automatiquement. »
- [x] **Réessai automatique côté client** : `useCallLogs` retente
      (`refetch`) 2 fois à 30 s d'intervalle sur un 429, la carte
      l'indique ; `Retry-After` non exposé par RingCentral → délai fixe.
- [x] **Alignement sur `docs/ringcentral.md`** :
      * contenu d'enregistrement via `/account/~/recording/{id}/content`
        (**1 appel**, conforme au §Step 3) avec repli `contentUri`
        (`media.ringcentral.com`) en cas de 404 ;
      * démarrage d'enregistrement : `…/parties/{partyId}/recordings`
        (sans corps) **avec repli** `…/record` + `{"id": "recording-request"}`
        (§Task 2) si l'URL renvoie 404/405 — un 400 « partie non connectée »
        n'active pas le repli ;
      * call log `view=Detailed` : la doc confirme que `recording.id` +
        `recording.contentUri` y figurent, et **qu'aucun filtre `deviceId`**
        n'existe côté RingCentral → le filtrage se fait côté serveur et se
        désactive tout seul (`filtered_by_device: false`) quand les
        journaux n'en portent pas.

### ✅ Passage au réel — stockage (Phase 1 + Phase 2)

Bases : samples officiels `/home/webmedia/work/github/ringcentral-api-code-samples`
(`provisioning/extensions/get-extension-list`,
`account/phone-numbers/get-extension-phone-number-list`,
`voice-telephony/call-log/get-user-call-log-records`,
`voice-telephony/call-recordings/*`, `voice-telephony/call-control/*`) +
`docs/ringcentral.md`.

#### Phase 1 — lier les employés à leurs numéros

- [x] **Migrations additives sur `employees`** : `ringcentral_extension_id`,
      `ringcentral_extension_number`, `ringcentral_phone_numbers` (json),
      `ringcentral_synced_at` (`2026_10_07_000001_…`, `php artisan migrate`).
- [x] **`RingCentralSyncService::syncEmployees()`** : `GET …/extension?type=User`
      + `…/device` + `…/phone-number` → correspondance **appareil choisi →
      e-mail → numéro** (les formats de numéro sont normalisés), écrite dans
      la fiche ; appareil + numéro source **préremplis** si la fiche était
      vide ; rapport `{total, matched, updated, unmatched[]}`.
- [x] **Endpoint** `POST /api/v1/call-logs/sync/employees` (`ADMIN,SUPER_ADMIN`)
      + bouton **« Synchroniser RingCentral »** en tête de la page
      « Liste des employés » (toasts du rapport).

#### Phase 2 — appels, enregistrements, historique

- [x] **Tables** `call_logs` + `call_recordings`
      (`2026_10_07_000002_create_call_logs_tables`) + modèles `CallLog` /
      `CallRecording` : appel dé-doublonné sur `ringcentral_call_id`
      (**unique**) avec repli `ringcentral_session_id` + employé (ligne
      ouverte avant la synchro) ; enregistrement dé-doublonné sur
      `ringcentral_recording_id` (unique). Métadonnées seules : l'audio
      reste streamé par le proxy.
- [x] **Appel sortant enregistré** : `POST /call-logs/my-call` ouvre la
      ligne `call_logs` dès la réponse du call-out (`recordOutboundCall`)
      et range l'enregistrement dès son démarrage (`attachRecording`) — la
      lecture est possible sans attendre la synchro ; **aucun échec de
      persistance ne casse l'appel** (test dédié).
- [x] **Synchro du journal** `POST /call-logs/employees/{id}/logs/sync` :
      call log `view=Detailed` écrit dans `call_logs` + `call_recordings`
      sans doublon → rapport `{extension_id, resolved_by, fetched, created,
      updated}` ; le poste résolu est **mémorisé sur la fiche** (la 2ᵉ
      synchro ne rebondit plus sur `/device` — quota).
- [x] **Lecture locale** `GET /call-logs/employees/{id}/logs` : lit
      `call_logs` (**aucun appel RingCentral** à l'ouverture de l'onglet :
      ni latence, ni `429 CMN-301`), réponse à la **même forme** que
      RingCentral (`records[].startTime / from.phoneNumber / result /
      recording.id`) — le front ne distingue pas le local du distant.
- [x] **Frontend** : bouton **« Synchroniser »** de l'onglet « Appels »
      (affiche le rapport ajoutés / mis à jour), état vide explicite,
      action de repli « Relier les employés aux numéros RingCentral » sur
      un `422` (aucune extension), lecture audio inchangée (proxy blob).
- [x] **Tests + build** : `RingCentralSyncTest` (9 tests : RBAC,
      correspondance appareil / e-mail / numéro, défauts préremplis,
      non-appariés, 502, appel sortant + enregistrement, échec de
      persistance) + `EmployeeCallLogsTest` réécrit (12 tests : RBAC,
      lecture locale sans API, synchro + dé-doublonnage, 422, 502, proxy) ;
      suite complète **253 passed / 2 failed** (2 échecs préexistants non
      liés) ; `vite build` vert ✅ fait.

#### ⏳ Restant

- [ ] **Vérification live** : **par vos soins** — bouton « Synchroniser
      RingCentral » (liste des employés) → « Synchroniser » dans l'onglet
      « Appels » → lecture d'un enregistrement.
- [ ] **Compte** : persister `account_id` + infos société.
- [ ] **Suivi d'appel** : tables sessions / événements (`sessionId`,
      statuts, `partyId`) + raccroché / suivi côté `COMERCIAL` (l'appel
      sortant et l'enregistrement le sont déjà : `POST /call-logs/my-call`).


## Phase 7 — Écarts API (audit du 2026-09-29) ⏳

Audit complet des routes (`routes/api/*.php`), de `CallWorkflowService`, des
contrôleurs notes/issues et de l'appelant React. **Aujourd'hui un flux unique**
depuis la fusion `call_outcomes` → `notes` (Phase 8) : le champ note du modal
**Suite appel** et `POST /notes` écrivent la même table `notes`. Priorité :
🔴 haute · 🟠 moyenne · 🟡 basse.

### 🔴 Sécurité / autorisations

- [x] **Notes sans contrôle de propriété** — `NoteController@destroy`
      (`app/Http/Controllers/Api/V1/Commercial/NoteController.php`) : **corrigé**
      → commentaire (`type = NOTE`) uniquement, auteur ou ADMIN/SUPER_ADMIN ;
      les événements du workflow renvoient 422.
- [ ] **`GET /clients/{clientId}/notes` sans filtre de rôle** (`NoteController@index`)
      expose le journal de n'importe quel client à tout employé (les listes sont
      globales : acceptable ?) → à trancher dans `docs/RULES.md` §6.
- [ ] **Garde `OutcomeController` sur une réservation non active**
      (`OutcomeController.php:37-41`) : elle lit la *dernière* réservation du
      connecté **quel que soit son statut** → un employé dont la réservation est
      déjà `NO` peut pousser `YES`/`BV`/`CALL_BACK` par l'API alors que le
      bouton « Suite appel » est masqué dans l'UI (`ClientController`
      utilise `ACTIVE_STATUSES` + client `RESERVED`/`CONFIRMED`).
      → Aligner sur `Reservation::scopeActive()` + tests 422/403.

### 🔴 Machine à états du workflow

- [ ] **Aucune garde d'état dans `CallWorkflowService::apply`** — seules les
      réservations sont vérifiées, jamais le statut du client :
      * `YES` sur un client `BLACKLISTED` → `clients.status = CONFIRMED`
        (le flag `is_blacklisted` reste vrai) ;
      * `BV` / `CALL_BACK` forcent `clients.status = RESERVED` même si le
        client était `CONFIRMED` ou `BLACKLISTED` ;
      * un 2e `BV` après un `YES` rétrograde un client confirmé.
      → Trancher les règles dans `docs/RULES.md` §3, les implémenter et couvrir
      par tests (`tests/Feature/CallWorkflowServiceTest.php`).
- [x] ~~**`OUI` ne nettoie ni `returned_at` ni `is_blacklisted`**~~ →
      `handleYes` vide `returned_at`, remet `is_blacklisted` à faux et annule le
      rappel (test `yes_moves_client_and_reservation_to_confirmed`).

### 🟠 Incohérences de données / validation

- [x] ~~**`call_outcomes.recall_amount` / `recall_unit` jamais renseignés**~~ →
      table supprimée : le délai affiché vient de `Rappel::delay()` (colonne
      `reminder_date`), recalculé à chaque affichage.
- [ ] **`max:5000` mensonger** : déclaré dans `OutcomeController.php:22`,
      `ClientController@blacklist` et `CommercialAdminController@blacklist`,
      alors que la vraie limite est **8 mots** (trait `LimitsNoteWords`,
      modèle) → 422 « La note ne peut pas dépasser 8 mots. » après coup.
      → Validation alignée (message clair) sur les 3 endpoints.

### 🟠 Notes — parcours incomplet

- [ ] **Pas d'édition de note** : routes = `index` / `store` / `destroy` seul
      (`routes/api/commercial.php:15-17`) alors que `docs/RULES.md` §6 parle de
      la limite « à la création **et à l'édition** ».
      → `PUT/PATCH notes/{id}` (propriétaire) + test, ou corriger RULES §6.
- [ ] **`POST /notes` sans contexte métier** : accepté sur n'importe quel client
      (blacklisté, réservé par un tiers, sans réservation) et sans impact sur
      statut/KPI — à confirmer comme comportement voulu.
- [ ] **UI de création de note inexistante** : `useCreateNote()`
      (`useNotes.js:16`) et `createNoteApi` ne sont **jamais importés** ; le seul
      bouton lié est la corbeille de `NoteTimeline.jsx:107`. → soit brancher un
      formulaire (onglet Historique), soit supprimer le code mort + la route
      `POST notes` si le flux est définitivement abandonné.

### 🟡 Propreté / docs

- [ ] `frontend/src/api/comercial.api.js` = stub vide (« TODO: implémenter les
      contrôleurs `Api\V1\Comercial\` ») → supprimer.
- [ ] `GET /commerciaux/statistiques` (`entreprise.api.js:25`) : **route backend
      inexistante** ; appelée par `useComercialStats()` qui n'est nulle part
      importé → supprimer les deux, ou implémenter l'endpoint.
- [ ] Rôle **`ENTREPRISE`** documenté dans `docs/RULES.md` §1 mais absent du
      backend (aucune occurrence dans `backend/app` ; les routes `enterprises`
      sont ADMIN/SUPER_ADMIN) → corriger RULES §1 ou réellement ajouter le rôle.
- [ ] Tests Feature à ajouter : ownership (lecture) des notes, garde d'état sur
      `POST clients/{clientId}/outcome`, rappel `CALL_BACK`
      (`ReservationWorkflowApiTest` — déjà couverts côté service, ne pas
      dupliquer).

## Phase 8 — Alignement modèle (diagramme PlantUML) 🔄

Objectif : coller au diagramme fourni — statuts renommés en base, **vraie table
`rappels`**, `call_outcomes` **fusionnée dans `notes`**. Aucune perte de
données : migrations UPDATE/copy avec `down()` réversible.

### Base de données — migrations **appliquées** à MySQL `rbqbot` (2026-09-29)

Backup avant migration : `/tmp/opencode/rbqbot-before-2026_09_29.sql`.

- [x] `2026_09_29_000001_rename_status_values_for_new_model` :
      `EN_ATTENT→PENDING`, `OUI→YES`, `NON→NO`, `BV→BV_VOICEMAIL`,
      `INJOINABLE→CALL_BACK` (réservations) ; `SUCCESS→CONFIRMED`,
      `UNAVAILABLE_TEMP→UNAVAILABLE` (clients). `up()` = UPDATE en base,
      `down()` = UPDATE inverse.
- [x] `2026_09_29_000002_create_rappels_table` : table `rappels`
      (`id`, `client_id`, `comercial_id`, `reservation_id`, `reminder_date`)
      + reprise des rappels existants (`recall_at` → `reminder_date`) +
      `dropColumn` de `recall_at` / `rappel_after` / `rappel_type`.
- [x] `2026_09_29_000003_merge_call_outcomes_into_notes` : recréation de
      `notes` au schéma final (`sender_id` texte nullable = UUID ou `SYSTEM`,
      `description` ex-`content`, `type` à 8 valeurs), recopie des 13 issues
      (`OUI→YES`, `NON→NO`, `BOITE_VOCALE→BV`, `INJOINABLE→CALL_BACK`,
      `BLACKLIST→BLACKLISTED`, `UNBLACKLIST→RETURNED_TO_AVAILABLE`), drop de
      `due_date` / `call_duration_seconds`, suppression de `call_outcomes`
      (`down()` la recrée et y recopie les événements).
      ⚠️ recréation de table plutôt que `dropColumn()` : SQLite refuse de
      supprimer une colonne référencée par une clé étrangère.

**Reprise vérifiée en lecture seule sur `rbqbot`** : `notes` = 13 lignes
(`BV` 7, `CALL_BACK` 3, `YES` 1, `NO` 1, `BLACKLISTED` 1), `sender_id` jamais
nul, `rappels` = 6 lignes, réservations `PENDING` 22 / `BV_VOICEMAIL` 6 /
`CALL_BACK` 2 / `NO` 1 / `YES` 1, clients `RESERVED` 27 / `CONFIRMED` 1 /
`UNAVAILABLE` 3 / `BLACKLISTED` 1, table `call_outcomes` supprimée.

### Modèles

- [x] `Client` : constantes `STATUS_*`, `STATUSES`, `displayStatus()`
      (`IN_PROGRESS` dérivé, jamais stocké), `scopeAvailable`,
      `loadLatestReservations`, relations `notes()` / `rappels()`.
- [x] `Reservation` : statuts `PENDING / YES / NO / BV_VOICEMAIL / CALL_BACK`
      (+ `REALIZED` réservé), `PROCESSED_STATUSES`, `scopeActive`,
      relation `rappel()`, colonnes de rappel supprimées.
- [x] `Rappel` **créé** : `delay()`, `delayPayload()`, `isDue()`.
- [x] `Note` : 8 types (`RESERVED`, `YES`, `NO`, `BV`, `CALL_BACK`,
      `BLACKLISTED`, `RETURNED_TO_AVAILABLE`, `NOTE`), `CALL_TYPES`,
      `SENDER_SYSTEM`, scopes `calls()` / `comments()`, `description`,
      immuabilité des événements, limite 8 mots.
- [x] `CallOutcome` **supprimé** (fusionné, pas mort).
- [x] `User` + `Employee` **conservés** (les 8 modèles sont utilisés).

### Backend (services, contrôleurs, crons)

- [x] `CallWorkflowService` : événements `Note::TYPE_*`, écriture dans `notes`,
      rappels créés/supprimés via `Rappel`, refus de l'événement inconnu avant
      toute écriture, `handleRecallExpired(Rappel)`, `handleYes` nettoie
      `returned_at`.
- [x] `OutcomeController` (validations `Note::TYPE_*`, alias `type=BV`),
      `NoteController` (ownership + immuabilité), `ClientController`
      (payload `notes`, `my_reservation` via rappel, blacklist
      `TYPE_BLACKLISTED`), `ReservationGroupController` (compteurs par
      nouveaux statuts), `ReminderController` (lit `rappels`,
      `?type=BV|CALL_BACK`), `ReservationController` (évènement `RESERVED`),
      `CommercialAdminController`, `DashboardController`, `AdminController`
      (déblocage → `RETURNED_TO_AVAILABLE`), `ProspectOverviewController`.
- [x] Cron : `clients:process-timeouts` boucle sur
      `Rappel::where('reminder_date','<=',now())` ; `clients:reactivate`
      journalise `RETURNED_TO_AVAILABLE` avec `sender_id = 'SYSTEM'`.

### Tests

- [x] `CallWorkflowServiceTest` réécrit (24 tests), `ReservationWorkflowApiTest`
      et `ClientLicenceFieldsApiTest` alignés.
- [x] Suite : **69 passed / 2 failed** — les 2 échecs sont **préexistants**
      (recherche par catégorie JSON sur SQLite, baseline 68/2).

### Frontend

- [x] `api/outcomes.api.js` + `useReminders*` : `type` par défaut `CALL_BACK`.
- [x] `useActionModal` : valeurs `YES` / `NO` / `BV` / `CALL_BACK`.
- [x] `useNoteTimeline` + `NoteTimeline` : **flux unique** (`notes`), 8 types,
      émetteur affiché, suppression réservée aux commentaires.
- [x] `useClientDetail` : plus de `call_outcomes` (journal = `notes`).
- [x] `ClientStatus`, `ReservationStatusBadge`, `ClientsHistoryToolbar`,
      `GroupDetail`, `Sidebar`, `Reminders` / `AutoRappels` : nouveaux statuts
      (anciennes clés gardées en repli de lecture).
- [x] `npm run build` ✅ (`rbqbot-frontend`).

### Docs

- [x] `docs/RULES.md` §1–§6, §7, §9–§12 réalignés.
- [x] `docs/models.puml` **créé** (diagramme du modèle + table de
      correspondance ancien → nouveau).
- [x] ~~Appliquer `php artisan migrate` sur MySQL `rbqbot`~~ → fait (aucun
      `migrate:fresh`, backup avant + reprise vérifiée, cf. ci-dessus).

## Phase 9 — Corrections & TODOs Admin/Commercial (2026-10-06) ✅

Lot de bugs + TODOs relevés sur l'app (badges, « Sans téléphone », numéro à
saisir, libération de liste, paliers, fiche client, stats d'entreprise).

### 🐛 Bugs d'interface

- [x] **Refetch des badges statistiques** : invalidation de `prospect-kpis`
      (avec `admin-clients-history`, `reservation-group(s)`,
      `active-reservations-count`, `comercialDetail`, `dashboard-stats`) au
      blacklist / déblocage (`useClientsHistory.js`, `useClientDetail.js`) et
      à **chaque issue d'appel** (`useOutcomes.js`) — les compteurs ne sont
      plus en retard après une mutation.
- [x] **« Répondant » remonté** : bandeau de la fiche client (sous le nom
      d'entreprise) + bloc **Contact** en tête de l'onglet *Détails*.
- [x] **Badges du détail client colorés** : téléphone, e-mail, représentant et
      répondants rendus en **badges `info`** (`ClientDetailsTab.jsx`) ;
      `E-mail` / `Téléphone` retirés des `DetailRow` d'*Identification* (plus
      de doublon).
- [x] **Bouton « Actualiser »** (onglet *Appels*) : vérifié — appelle
      `query.refetch()` sans condition ; c'est le seul « Actualiser » de
      l'application. Aucun correctif nécessaire.

### 📱 Statut « Sans téléphone »

- [x] **Backend** : `Client::STATUS_SANS_TELEPHONE` (**dérivé**, jamais
      stocké) + scopes `withoutPhone()` / `withPhone()` ; valeur acceptée par
      `scopeFilterByStatuses()` ; **8e badge** `by_display_status`
      (`ProspectOverviewController`, `CommercialAdminController`) via les
      helpers partagés `Client::applyDisplayStatusFilters()` /
      `Client::displayStatusCounts()`.
- [x] La **liste commerciale** (`scopeProspectList()` → `GET /clients` **et**
      le lot réservé) exclut les prospects sans numéro ; `mine()` et la liste
      admin restent inchangés.
- [x] Un payload n8n **sans `phone` (ou vide) n'efface plus** un numéro déjà
      saisi (`Client::upsertFromScraperPayload()`).
- [x] **Frontend** : 8ᵉ pill **« Sans téléphone »** (`rose-600`) dans
      `ProspectKpis` + `CLIENT_STATUS_KEYS` (dimension `status` côté serveur).

### 🔢 Paliers & libération de liste

- [x] Paliers **50 / 80 / 100 / 120** (`in:50,80,100,120` BE,
      `COUNT_OPTIONS` FE) — remplace 200 / 250 / 300.
- [x] **« Libérer la liste »** : `POST reservations/release-pending` —
      toutes les réservations encore `PENDING` du connecté redeviennent
      `AVAILABLE` (client remis en `AVAILABLE` + `returned_at` vidé, rappels
      supprimés, réservation supprimée → resync de `current_reservation_id`,
      note `RETURNED_TO_AVAILABLE`) ; les réservations **déjà traitées** et
      celles des autres employés sont conservées. Réponse
      `{released, pending, can_reserve}`.
- [x] **Frontend** : la modale « Réserver des prospects » s'ouvre **aussi**
      quand la liste est incomplète (le bouton « Réserver » n'est plus
      bloquant côté page) et y propose **« Libérer la liste »** — le
      `409 unfinished_treatment` n'est plus une impasse.

### 🛠 Onglets Admin / Commercial

- [x] **Filtre** : « Sans téléphone » rejoint *Disponible* / *Blacklist* dans
      la dimension `status` (sélection unique, union `OR` avec
      `reservation_status`) — les 8 badges sont **LE filtre de statut**.
- [x] **Bouton admin de saisie du numéro** : `PATCH
      commercials/clients/{id}/phone` (`CommercialAdminController::updatePhone`,
      `Client::cleanPhone()` rendue publique ; champ **vide = numéro retiré**)
      + modale partagée `pages/shared/components/PhoneEditModal` branchée sur
      la Grande liste (tableau **et** carte, icône `SquarePen`) et sur la fiche
      client (bouton + pastille « Sans téléphone » dans le bandeau).
- [x] **Stats d'entreprise** : `GET entreprises/{id}/stats`
      (`EnterpriseController::stats()` — analytics agrégés, tableau des
      employés par **requêtes groupées** (jamais une requête par employé),
      historique commun paginé avec les 8 badges) + page **`/entreprises/:id`**
      (`pages/shared/entrepriseDetail`, `StatCards` + onglets
      *Détails entreprise / Employés / Historique*, réutilise les composants
      `comercialDetail`) + entrée depuis la liste des entreprises (nom
      cliquable et « Voir »).

### 🧭 Navigation, fiche client & page entreprise (2026-10-07)

- [x] **Breadcrumbs supprimés de toutes les pages** (filiants « Accueil › … ») :
      11 pages nettoyées + composants morts supprimés
      (`pages/shared/comercialDetail/components/BreadcrumbNav.jsx`,
      `components/layout/Breadcrumb.jsx`, `useSettingsHeader.js`), handlers
      `handleHomeClick` / `handleProspectsClick` / `handleAccueilClick` /
      `handleMesListesClick` retirés des hooks. **Les boutons retour sont
      conservés** (flèche `ArrowLeft` de la fiche client et de `GroupDetail`,
      lien « Retour » des pages de détail).
- [x] **Bouton « Appeler » ajouté à la fiche client** : `CallButton` partagé
      (même rendu que dans les tableaux/cartes), placé avant « Suite appel »
      et rendu **dans la même condition** `!isAdmin && hasReservation` → les
      deux boutons apparaissent et disparaissent **exactement ensemble**.
- [x] **🐛 Enveloppe de `GET entreprises/{id}/stats`** : `stats()` répond
      `{success, data: {entreprise, analytics, employees, historique}}` mais
      `useEntrepriseDetail.js` lisait `data.entreprise` → `entreprise`
      restait `undefined` et la page affichait **« Entreprise
      introuvable. » même avec une réponse HTTP 200**. Correction : déballage
      de `body.data` (+ `errorMessage` = message serveur affiché tel quel,
      ce qui distingue un vrai `404` d'une réponse incomplète).
- [x] **Test** `EnterpriseStatsApiTest` (2 tests / 15 assertions) : verrouille
      l'enveloppe, le périmètre (appels des seuls employés de l'entreprise),
      l'historique commun et le `404 {"success":false,"message":"Entreprise
      introuvable."}`.
- [ ] **⚠️ Prod — origine de l'API** : le build de production utilise
      `VITE_API_URL=http://51.255.192.50` alors que la page est ouverte sur
      `http://zdigia.com` → requêtes **cross-site** : cookies `XSRF-TOKEN` et
      `laravel-session` rejetés par le navigateur (console) + avertissement
      « Password fields present on an insecure (http://) page ». À faire :
      passer `VITE_API_URL` (et `APP_URL` / `FRONTEND_URL`) sur
      **`http://zdigia.com`** — le gateway accepte déjà le domaine
      (`server_name _`, `location /api` proxy) — puis activer HTTPS.

### ✅ Vérifications

- [x] `docs/RULES.md` : §2 (statut dérivé), §7 (paliers, libération,
      exclusion de la liste commerciale), §9 (8 badges, couleur `rose-600`,
      fiche Contact, page entreprise), §10 (3 nouvelles routes).
- [x] Tests backend : **253 passed / 2 failed** — les 2 échecs sont les
      **préexistants** (recherche par catégorie JSON sur SQLite, baseline
      68/2). Nouveaux : `ClientWithoutPhoneTest` (6),
      `test_release_pending_frees_untreated_clients_and_keeps_treated_ones`,
      paliers dans `ReservationWorkflowApiTest`, 8 badges dans
      `CurrentReservationStatusBadgesTest`, enveloppe + 404 de la fiche
      entreprise dans `EnterpriseStatsApiTest` (2 tests / 15 assertions).
- [x] `npm run build` (`vite build`) **vert** ✅.

## Points ouverts

- [ ] **Effet de bord** : un test de cron a traité la vraie réservation
      « Maçonnerie Girard & frères » (client `01a0c396-…`) : bv_count 2→3,
      client `UNAVAILABLE` jusqu'au 2026-10-15. **À confirmer : annuler ou
      conserver.**
- [ ] **F-20 à confirmer** : interprétation retenue — masquer les lignes avec
      un rappel planifié (gérées dans « Rappels » / « Auto-rappels ») et colorer
      en *trail row* les lignes de suivi `BV_VOICEMAIL` / `CALL_BACK` sans
      rappel.
- [x] ~~Rien n'est encore commité~~ → working tree **salie** par le chantier
      Phase 7 + Phase 8 (migrations, modèles, contrôleurs, tests, frontend,
      docs) : **commit à proposer** — dernier commit `dfcd019 docs: display
      status rules…`.
