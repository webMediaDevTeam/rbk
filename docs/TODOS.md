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
      (INJOINABLE affiché « à RAPPELER »).
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
      NON/BV propres ; `pendingCount` / `releasePending` supprimés.
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
      libre supprimé (côté FE `COUNT_OPTIONS` et BE `in:200,250,300`).
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
- [x] `useNoteTimeline` : `BV` (affiche « À RAPPELER » pour INJOINABLE),
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
- [ ] **Webhook n8n** : endpoint, contrat payload, auth `X-N8N-Token`, validation
      NOT NULL côté webhook — **reporté** (décision projet).

## Points ouverts

- [ ] **Effet de bord** : un test de cron a traité la vraie réservation
      « Maçonnerie Girard & frères » (client `01a0c396-…`) : bv_count 2→3,
      client `UNAVAILABLE_TEMP` jusqu'au 2026-10-15. **À confirmer : annuler ou
      conserver.**
- [ ] **F-20 à confirmer** : interprétation retenue — masquer les lignes avec
      `recall_at` (gérées dans « Rappels » / « Auto-rappels ») et colorer en
      *trail row* les lignes de suivi BV/INJOINABLE sans rappel planifié.
- [ ] Rien n'est encore commité (tout est en working tree).
