# RBQBot — Règles métier (référence unique)

> Document consolidé : fusion de `permission_and_rules.md` (rôles/permissions) et
> `UDAPTE.md` (workflow d'appel). Ce fichier fait foi ; les deux documents sources
> ont été supprimés.

## 1. Rôles & permissions

* **COMERCIAL** — réserve des lots de prospects, enregistre les issues d'appel
  (YES / NO / BV / CALL_BACK), consulte ses listes et ses rappels.
* **ENTREPRISE** — CRUD complet sur les employés, consultation des historiques
  de clients, statistiques de performance des employés.
* **ADMIN** — toutes les permissions d'ENTREPRISE, plus : créer des entreprises,
  consulter la liste noire, débloquer des clients (`POST liste-noire/{id}/debloquer`,
  réservé ADMIN / SUPER_ADMIN).
* **SUPER_ADMIN** — toutes les permissions d'ADMIN, plus : créer des admins.

Seul endpoint de déblocage : `POST api/v1/liste-noire/{id}/debloquer`
(l'ancien `POST commercials/clients/{id}/unblock` a été supprimé).

**Libellés UI — « Employé / Employés »** : le terme affiché à l'écran est
**« Employé / Employés »** (anciennement « Commercial / Commerciaux »). Les
identifiants techniques (`/commerciaux`, rôle `COMERCIAL`, classes/variables
`Commercial*`) sont inchangés ; seuls les textes visibles (sidebar, titres,
breadcrumbs, modals, toasts, placeholders, badge de rôle, note d'auto-blacklist)
ont été renommés.

Renommage de groupe : `PATCH api/v1/reservation-groups/{id}` — propriétaire du
groupe ou ADMIN / SUPER_ADMIN.

## 2. Statuts

> **Vocabulaire aligné sur le modèle** (`docs/models.puml`) : les renommages de
> `2026_09_29_000001`, la table `rappels` de `…_000002` et la fusion
> `call_outcomes` → `notes` de `…_000003` ont posé ces valeurs en base.
> Les anciennes valeurs (`EN_ATTENT`, `OUI`, `NON`, `INJOINABLE`, `SUCCESS`,
> `UNAVAILABLE_TEMP`) n'existent plus.

### Clients (`clients.status`)

| Statut | Signification |
|---|---|
| `AVAILABLE` | Disponible à la réservation (règle `scopeAvailable`) |
| `RESERVED` | Réservé par un employé (appel en cours : BV / À rappeler / en attente) |
| `CONFIRMED` | Confirmé (issue `YES`) — définitif jusqu'à clôture admin |
| `UNAVAILABLE` | Indisponible temporairement pour **tous** les employés ; `returned_at` porte la date de retour |
| `BLACKLISTED` | Liste noire (`is_blacklisted = true`) |

`scopeAvailable()` : `status = AVAILABLE` **et** (`returned_at` null ou passé).

### Statut affiché (`display_status`)

Champ **dérivé** (jamais stocké) renvoyé avec chaque client et affiché par le
composant partagé `pages/shared/components/ClientStatus` : **couleur par
statut** (vert / ambre / rouge / bleu), **sauf** le badge « Liste noire » qui
reste **neutre** — fond **noir en mode clair**, **gris en mode sombre**
(variables `--status-badge` / `--status-badge-foreground` de
`styles/theme.css`, pilotées par la classe `.dark`). `clients.status` brut,
lui, reste celui des filtres, des KPI et du workflow : il n'est **jamais
modifié** par ce mécanisme.

Un client **RESERVED** est qualifié par l'état de sa **dernière réservation**
(`Client::displayStatus()` ; dans une liste, la dernière réservation est
préchargée en **une seule requête** par `Client::loadLatestReservations()`,
pas une requête par ligne) :

| Dernière réservation | `display_status` | Badge affiché |
|---|---|---|
| `YES` | `CONFIRMED` | **Confirmé** |
| `NO` | `UNAVAILABLE` | **Non disponible** + « Retour dans … » sous le badge (`returned_at`) |
| `BV_VOICEMAIL` | `IN_PROGRESS` | **En cours de traitement** |
| `CALL_BACK` | `IN_PROGRESS` | **En cours de traitement** |
| `PENDING` ou aucune | `RESERVED` | **Réservé** |

Tous les autres statuts sont renvoyés tels quels (`AVAILABLE`, `CONFIRMED`,
`UNAVAILABLE`, `BLACKLISTED`…). Le composant écrase toujours par
« Liste noire » si `is_blacklisted`.

`IN_PROGRESS` n'est **jamais stocké** : c'est la valeur d'affichage d'un
client `RESERVED` dont la dernière réservation est en cours (BV / À rappeler).
Les clés `SUCCESS`, `UNAVAILABLE_TEMP`, `IN_PROGRESS_RECALL`, `VOICEMAIL` et
`INJOINABLE` restent déclarées côté UI **uniquement** pour lire d'éventuelles
données non migrées.

### Réservations (`reservations.status`)

`PENDING`, `YES`, `NO`, `BV_VOICEMAIL`, `CALL_BACK` (+ `REALIZED`, valeur
réservée du modèle **jamais émise** par le workflow actuel).

* **CALL_BACK s'affiche « À rappeler »** dans l'UI ; `BV_VOICEMAIL` s'affiche
  « Boîte vocale ».
* Aucune expiration : la colonne `expires_at` a été supprimée. Les réservations
  **actives** sont `PENDING / YES / BV_VOICEMAIL / CALL_BACK` **et** le client
  est `RESERVED` ou `CONFIRMED` (`Reservation::scopeActive()`).
* Il n'existe **aucune libération manuelle** : plus d'endpoint « release ».
  `CONFIRMED` est définitif ; seul le déblocage admin supprime les réservations.
* « Traités » = `Reservation::PROCESSED_STATUSES` = `YES / NO / BV_VOICEMAIL /
  CALL_BACK` ; « restant » = `PENDING`.

### Événements du journal (`notes.type`)

Le journal unique `notes` porte les issues d'appel **et** les événements du
workflow (`Note::CALL_TYPES` = événements d'appel, les autres sont des
événements ou un commentaire) :

| `notes.type` | Sens | Émetteur |
|---|---|---|
| `RESERVED` | client réservé par un employé | l'employé |
| `YES` / `NO` / `BV` / `CALL_BACK` | issue d'appel | l'employé |
| `BLACKLISTED` | passage en liste noire | l'employé |
| `RETURNED_TO_AVAILABLE` | retour en `AVAILABLE` (réactivation cron ou déblocage admin) | `SYSTEM` ou l'admin |
| `NOTE` | commentaire libre (créable/supprimable via l'API) | l'auteur |

## 3. Workflow d'appel (`CallWorkflowService::apply`)

Constantes : `RECALL_DAYS = 3`, `NON_BLOCK_MONTHS = 3`, `TEMP_BLOCK_DAYS = 21`,
`ATTEMPTS_LIMIT = 2`.

* **YES** → client `CONFIRMED`, réservation `YES`, `returned_at` vidé, rappel
  annulé (`is_blacklisted` remis à faux). La réservation est maintenue jusqu'à
  clôture admin (pas de libération manuelle).
* **NO** → client `UNAVAILABLE`, `returned_at = now + 3 mois`, réservation
  `NO`. Puis vérification d'**auto-blacklist** : si *tous les employés actifs*
  ont répondu NO pour ce client, il passe en `BLACKLISTED` (un événement
  `notes` `BLACKLISTED` est journalisé : « Tous les employés actifs ont répondu
  NON. »).
* **BV** → le client reste `RESERVED`, `bv_count++`, **rappel automatique à
  3 jours** créé dans `rappels` (`reminder_date = now + 3d`, délai affiché
  `3 JOUR`). Si `bv_count >= 2` → `UNAVAILABLE`, `returned_at = now + 21 jours`,
  rappel annulé.
* **CALL_BACK** (affiché « À rappeler ») → identique à BV avec
  `injoinable_count`, **mais le rappel est fixé par l'employé** : `recall_at`
  est **obligatoire** (`required_if:outcome,CALL_BACK`, doit être dans le futur)
  et saisi via un champ `datetime-local` dans le modal (aucun select
  minute/mois). Le délai affiché (`recall_after` / `recall_unit`) est
  recalculé dans l'unité la plus lisible (`MINUTE` / `HEURE` / `JOUR`). Sans
  saisie (appel direct du service), repli sur le rappel automatique à 3 jours.
* **BLACKLISTED** → client `BLACKLISTED`, `is_blacklisted = true`,
  `returned_at = null`. La liste noire **ne supprime pas** les réservations
  (elles deviennent inactives via le statut client).

À chaque écriture : création d'une ligne `notes` (événement, `sender_id` =
l'employé, `description` = note saisie) **puis** mise à jour de la
réservation/croisement des compteurs, le tout dans une transaction. Un
événement inconnu est rejeté **avant** toute écriture (`InvalidArgumentException`).

La note est **optionnelle sur toutes les issues** (y compris BLACKLISTED).

## 4. Rappels (« Suite appel »)

> **Table `rappels` dédiée** (migration `2026_09_29_000002`) : les colonnes
> `recall_at` / `rappel_after` / `rappel_type` ont disparu de `reservations`
> (reprise des rappels existants en INSERT). Schéma : `id` UUID, `client_id`
> (FK clients, cascade), `comercial_id` (FK users), `reservation_id` (FK
> reservations, cascade), `reminder_date` ; `Rappel::delay()` calcule le délai
> affiché dans l'unité la plus lisible, `isDue()` signale un rappel échu.

* Le bouton **« Suite appel »** n'apparaît que si le client est **réservé par le
  commercial connecté** (`my_reservation` non nul, réservation active + client
  `RESERVED`/`CONFIRMED`) et que l'utilisateur n'est pas admin. Sinon il est
  **masqué** (l'admin n'a jamais ce bouton).

* Rappel **BV : fixe et 100 % automatique — 3 jours**, aucune saisie utilisateur
  (les options SEMAINE / MOIS et la saisie de durée ont été supprimées).
* Rappel **CALL_BACK : datetime libre** choisie dans le modal « Suite appel »
  (champ `datetime-local`, obligatoire, dans le futur).
* Rappel expiré **sans action** (`clients:process-timeouts`, toutes les 10 min) :
  le compteur correspondant est incrémenté, **le rappel est supprimé** et **la
  réservation n'est jamais supprimée**. Si compteur >= 2 → client `UNAVAILABLE`
  21 jours ; sinon le client réapparaît dans les listes (à rappeler
  manuellement). Un rappel portant sur un client `BLACKLISTED` est simplement
  retiré (aucun compteur).
* **Deux pages séparées** (`GET reminders?type=`) :
  * **« Rappels »** (`/reminders`, défaut `type=CALL_BACK`) : les rappels de
    réservations `CALL_BACK` ;
  * **« Auto-rappels »** (`/auto-rappels`, `type=BV`) : les rappels de
    réservations `BV_VOICEMAIL`.
  La réponse livre `recall_at` (= `reminder_date`), `recall_after` /
  `recall_unit` (= `delay()` recalculé) et le statut de la réservation.

## 5. Réactivation

* `clients:reactivate` (horodaté **chaque heure**) : les clients
  `UNAVAILABLE` dont `returned_at <= now` repassent `AVAILABLE`
  (`returned_at = null`) — **pour tous les employés** — et un événement
  `notes` `RETURNED_TO_AVAILABLE` est journalisé avec `sender_id = 'SYSTEM'`.
* Après un NO, le retour à 3 mois remet donc le client disponible **pour tout le
  monde** (l'ancienne règle « sauf ceux qui l'ont réservé » ne s'applique plus :
  la réservation NO n'est pas active).

## 6. Notes

* **Journal unique `notes`** : issues d'appel, événements du workflow et
  commentaires cohabitent dans la même table (fusion de `call_outcomes` dans
  `notes`, migration `2026_09_29_000003`). Colonne `sender_id` = **identifiant
  de l'expéditeur en texte** : UUID d'un utilisateur ou `'SYSTEM'`
  (`Note::SENDER_SYSTEM`) pour les écrits de cron — nullable, **sans clé
  étrangère** (l'historique ne disparaît pas avec un compte supprimé).
* `Note.description` (ex-`content`) : **8 mots maximum** à la création et à
  l'édition (`LimitsNoteWords`, `ValidationException` en français).
  Les notes legacy plus longues restent affichables (limite appliquée au save).
* Notes optionnelles sur **toutes** les issues d'appel.
* **Immuabilité** : seuls les commentaires (`type = NOTE`) sont modifiables /
  supprimables (`DELETE /notes/{id}` rejette les événements du workflow).
* `due_date` et `call_duration_seconds` ont été supprimés : aucune écriture
  possible depuis l'UI.

## 7. Groupes de réservation

* Création : `POST clients/reserver` avec `{count}` — **sans nom** : le champ
  « Nom de la liste » a été retiré du modal ; `group_name` reste accepté en
  optionnel (généré côté serveur : « Liste du JJ/MM/AAAA HH:MM » s'il est vide).
  (durée/expiration supprimées).
* `count` : **200 / 250 / 300 uniquement** (`in:200,250,300`), plus de nombre
  libre ; le modal propose seulement ces 3 boutons.
* Renommage : `PATCH reservation-groups/{id}` `{name}` — propriétaire ou admin.
* **Page liste de réservation (détail)** :
  * édition inline du nom de la liste ;
  * **masquage automatique** des lignes avec rappel planifié (`recall_at` non nul) —
    gérées depuis les pages « Rappels » / « Auto-rappels » (compteur
    « x rappel(s) masqué(s) ») ;
  * couleur de fond (*trail row*) sur les lignes de suivi (`BV_VOICEMAIL` /
    `CALL_BACK` sans rappel planifié).
* **Page « Mes listes » (tableau)** — compteurs et employé calculés par le
  serveur (`GET reservation-groups`), affichés automatiquement :
  * `Clients` = `reservations as clients_count` (réservations de la liste),
    `Demandé` = `total` ;
  * `Traités` = statuts `YES + NO + BV_VOICEMAIL + CALL_BACK`, `Restant` =
    `PENDING` (les deux retombent sur `Clients`) ;
  * colonnes `OUI` / `NON` / `BV` / `Injoinable` (clés JSON historiques
    `oui_count` / `non_count` / `bv_count` / `injoinable_count`, alimentées
    par les nouveaux statuts) ;
  * `Employé` = **jointure** sur `users` (`first_name + last_name`, repli sur
    `email`) et `Créé le` = `created_at` du groupe.

## 8. Recherche multi-critères (F-22)

Sur **Prospects (commercial)** et **Prospect list (admin)** :

* texte : nom (`name`), **entreprise** (`enterprise_name`), email, téléphone,
  NEQ, municipalité, licence, **répondants** (JSON), **catégorie**
  (colonne `categories`) ;
* **liste « Liste de tous les prospects »** — recherche étendue à **toutes les
  colonnes affichées** : nom d'entreprise, NEQ, numéro de licence
  (`licence_number`), licence propre (`licence_propre_numero`), répondants
  (`respondents`), catégorie (`categories`) **et** catégories autorisées
  (`authorized_categories`) ;
* filtre **catégorie** (commercial) et **statut** (admin) ;
* **filtres `municipality` / `category`** = **scopes Eloquent** du modèle
  `Client` : `filterByMunicipalities()` (`whereIn` sur le libellé exact) et
  `filterByCategories()` (contenance JSON via `whereJsonContains()` →
  `JSON_CONTAINS` sur MySQL / `json_each` sur SQLite, `OU` pour plusieurs
  valeurs). Le filtre s'applique à la requête du modèle — plus de
  `DB::table()` ni de sous-requête `whereIn('id', …)` ;
* **options des filtres** : `GET /filters` (groupé) ou, champ par champ,
  `GET /categories`, `GET /municipalities`, `GET /administrative-regions` —
  toutes les valeurs distinctes de la table `clients` lues via Eloquent
  (`Client::distinctValues()`, cast JSON appliqué, sans doublons) ;
* **recherche plein texte sur colonnes JSON** (`respondents`, `categories`,
  `authorized_categories`) : `Client::scopeOrWhereJsonTextLike()` — LIKE sur la
  représentation textuelle, seule option portable (`whereJsonContains()` exige
  une valeur exacte, `JSON_SEARCH()` est exclusif à MySQL). C'est les **seules**
  expressions SQL brutes du modèle, isolées dans ce scope ;
* **aucun filtre de date** : les champs « Du / Au » et les paramètres
  `date_from` / `date_to` ont été supprimés des deux listes.

## 9. UI — compteurs & badges

* **Sidebar** : badge sur « Mes listes » = nombre de **réservations actives** du
  commercial connecté (`GET reservations/active-count`, actualisé chaque minute) ;
  badge sur « Rappels » = rappels `CALL_BACK` échus ; badge sur
  « Auto-rappels » = rappels `BV` échus (`GET reminders/count?type=`).
* **Prospect list (admin)** : colonne « Retour » avec compte à rebours concis pour
  `UNAVAILABLE` (`returned_at`) : « 2 mois 3j », « 18j 04h », « 5h 30m ».
* Badges statut client : Disponible / Réservé / Confirmé / Indisponible / Liste noire.
* Badge réservation : En attente / Confirmé / Refusé / Boîte vocale / **À RAPPELER**.
* **Listes (prospects, historique, listes, employés…)** : colonnes `N°`
  (numéro d'ordre sur la page), `Entreprise`, `Répondants`, `N° de licence`,
  `NEQ`, `Catégorie`, `Statut` dans le tableau **et** dans les cartes mobiles.
* **Statut client (toutes les listes/tableaux)** : composant partagé
  `pages/shared/components/ClientStatus` (remplace l'ancien
  `ProspectStatusBadge`, supprimé). Il affiche `display_status` (§2) avec
  **une couleur par statut** — sauf « Liste noire », badge **neutre** (noir en
  mode clair, gris en mode sombre) —, écrase
  toujours en « Liste noire » si `is_blacklisted`, et fait apparaître sous le
  badge le compte à rebours « Retour dans … » quand
  `returned_at` est renseigné (NO : 3 mois, 2 BV/CALL_BACK : 21 j).
  Nouvelle colonne **« Statut »** (170 px) ajoutée à `ProspectTable`
  (Prospects + Prospect list) ; le composant est aussi utilisé par les cartes
  mobiles, le détail d'une liste, l'historique employé et la fiche client.
* **Overview KPI** — barre de **badges compacts** (une ligne, `flex-wrap`,
  hauteur ~32 px) juste au-dessus des filtres, sur **Prospects (commercial)**
  et **Prospect list (admin)**, alimentée par `GET clients/overview` (tous
  rôles, chiffres **globaux**, recalculés à chaque appel). Pastille couleur +
  libellé + valeur (chiffres tabulaires) :
  1. *Prospects* = hors liste noire · suffixe « X dispo · Y noirs » ;
  2. *Réservés* = total ;
  3. *Réservés traités* et 4. *Réservés non traités* = `x / total réservés` ;
  5. *Succès / traités* et 6. *En cours / traités* = `x / total traités` + % ;
  le survol d'un badge rappelle sa définition (`title`).

  **Traité** = au moins une issue d'appel (note `YES` / `NO` / `BV` /
  `CALL_BACK`) ; **en cours** = traité mais encore `RESERVED` (BV / à
  rappeler) ; **succès** = traité et `CONFIRMED` (issue `YES`).

  **Badges à 0 masqués** : un badge dont le compte principal vaut `0` n'est
  pas rendu (sur une base sans réservation, la barre ne montre que
  *Prospects*), et les segments de suffixe à 0 (« 0 noir », « 0 % ») sont
  retirés ; si aucun badge ne reste, la barre disparaît.
* **Mes listes** — mêmes badges compacts (composant partagé
  `pages/shared/components/KpiPill`, celui de l'overview) :
  * en-tête de `/mes-listes` → badge *Listes* = `pagination.total` (total
    toutes pages confondues, masqué à 0) ;
  * en-tête d'une liste ouverte (`/mes-listes/{id}`) → badges *Traités*
    (`traites_count`) et *Non traités* (`restant_count`), suffixe
    « sur N prospect(s) », masqués à 0 ;
  * même en-tête → badges d'issue **OUI**, **NON**, **BV**, **Injoinable**
    (clés `oui_count` / `non_count` / `bv_count` / `injoinable_count`,
    alimentées par les statuts `YES` / `NO` / `BV_VOICEMAIL` / `CALL_BACK`),
    masqués à
    0, dans les couleurs des badges de statut (success / destructive /
    warning / info).
  Ces deux compteurs sont produits par `GET reservation-groups/{id}`
  (`withCount`, mêmes définitions que le tableau `index`) — **jamais**
  recalculés côté client ; `traites_count + restant_count = clients_count`.
* **Icônes sidebar** : les deux entrées prospects (« Prospect list » /
  « Tous les prospects ») → `UserSearch` ; « Mes listes » → `ListChecks`
  (distinct de `List` utilisé par « Employés »).
* **Lignes par page : 50 / 100 / 200 / 300** (défaut 50) — plafond serveur
  `per_page` relevé de 100 à **300** sur tous les endpoints paginés.

## 10. Endpoints clés

| Méthode | URI | Rôle |
|---|---|---|
| GET | `reservations/active-count` | COMERCIAL |
| POST | `clients/reserver` | COMERCIAL |
| PATCH | `reservation-groups/{id}` | propriétaire ou ADMIN/SUPER_ADMIN |
| GET | `reservation-groups` (compteurs par statut + `employe`) | COMERCIAL |
| GET | `reservation-groups/{id}` (détail + `clients_count` / `traites_count` / `restant_count` / `oui_count` / `non_count` / `bv_count` / `injoinable_count`) | COMERCIAL |
| GET | `reminders?type=CALL_BACK\|BV`, `reminders/count?type=…` | COMERCIAL |
| POST | `clients/{clientId}/outcome` (`recall_at` requis si `CALL_BACK`) | COMERCIAL |
| POST | `clients/{id}/blacklist` | COMERCIAL |
| POST | `commercials/clients/{id}/blacklist` | ADMIN/SUPER_ADMIN |
| POST | `liste-noire/{id}/debloquer` | ADMIN/SUPER_ADMIN |
| GET | `clients/overview` | tous rôles — cartes KPI globales (prospects / réservés / traités) |
| GET | `filters` | tous rôles — `{categories, municipalities, administrative_regions}` distincts |
| GET | `categories` | tous rôles — libellés distincts de `clients.categories` |
| GET | `municipalities` | tous rôles — municipalités distinctes |
| GET | `administrative-regions` | tous rôles — régions administratives distinctes |

Les quatre filtres ci-dessus répondent `{success, data: [...]}` : valeurs
**sans doublons** (même à la casse près), triées, valeurs vides exclues — lues
par `Client::distinctValues()` (liste blanche `Client::DISTINCT_COLUMNS`),
**mises en cache une semaine** et invalidées dès qu'un client change.
`clients/overview` répond
`{success, data: {prospects, reserved, processed}}` et n'est **pas** caché :
les compteurs doivent bouger à chaque réservation et issue d'appel.

Toutes ces routes sont derrière `auth:sanctum` (**tous les rôles**, sans
`CheckRole`).

Routes supprimées : `release`, `release-pending` (×2), `pending-count`,
`commercials/clients/{id}/unblock`.

## 11. Cron

| Commande | Fréquence | Effet |
|---|---|---|
| `clients:process-timeouts` | 10 min | `rappels` échus → compteur++ / blocage 21 j, **rappel supprimé**, réservation conservée |
| `clients:reactivate` | horaire | `UNAVAILABLE` + `returned_at` passé → `AVAILABLE` (+ événement `RETURNED_TO_AVAILABLE`, `sender_id = SYSTEM`) |

## 12. Données de licence (payload n8n)

Le schéma `clients` est aligné sur le flux n8n **sans renommer aucune colonne
existante**. Mapping officiel payload → colonne :

| Payload n8n | Colonne `clients` | Type |
|---|---|---|
| `licence_propre` | `licence_propre_numero` | INT UNSIGNED, UNIQUE |
| `numero_licence` | `licence_number` | string |
| `nom_intervenant_entreprise` | `intervenant_name` (+ `clients.name` / `enterprise_name`) | string |
| `statut_licence` | `licence_status` | string (`valide` / `invalide`) |
| `neq` | `neq` | string (stockage texte) |
| `adresse_complete` | `full_address` | text |
| `municipalite` | `municipality` | string |
| `region_administrative` | `administrative_region` | string |
| `telephone` | `phone` | string |
| `repondants[]` | `respondents` (+ `respondent_count`) | JSON |
| `categories_sous_categories[]` | `authorized_categories` (+ `categories`) | JSON |
| `cautionnement_compagnie[]` | `cautionnement_compagnie` | JSON |
| `montant_caution` | `surety_amount` | DECIMAL(12,2) |
| `date_debut_delivrance` | `licence_start_date` | DATE |
| `date_fin_paiement_annuel` | `licence_end_date` | DATE |

**Nommage — clés d'affichage en français → colonnes snake_case.** Le payload
arrive avec des clés d'affichage (`Nom de l'intervenant / Entreprise`, …), pas
des noms de colonnes : c'est `Client::PAYLOAD_MAP`, lu par
`Client::attributesFromPayload()`, qui fait la correspondance (clés comparées
sans casse ni ponctuation — `l'intervenant` et `l’intervenant` donnent la même
colonne), aligne les types sur les casts du modèle (entier, décimal, date ISO →
`Y-m-d`, tableau), passe les chaînes vides `""` en `NULL` et ignore les clés
inconnues :

| Clé d'affichage du payload | Colonne `clients` |
|---|---|
| `Licence` | `licence_number` |
| `Licence (propre)` | `licence_propre_numero` |
| `Nom de l'intervenant / Entreprise` | `enterprise_name` (+ `name` et `intervenant_name` : le payload ne porte qu'un seul nom) |
| `Statut de la licence` | `licence_status` |
| `NEQ` | `neq` |
| `Adresse complète` | `full_address` |
| `Municipalité` | `municipality` |
| `Région administrative` | `administrative_region` |
| `Téléphone` | `phone` |
| `Courriel` | `email` |
| `Nombre de répondants` | `respondent_count` |
| `Répondants / Interlocuteurs (Qualifications)` | `respondents` |
| `Nombre de sous-catégories` | `sub_category_count` |
| `Catégories et sous-catégories autorisées` | `authorized_categories` |
| `Cautionnement (Compagnie / Association)` | `surety_company` (chaîne affichée ; `cautionnement_compagnie` reste le tableau) |
| `Montant de la caution ($)` | `surety_amount` |
| `Date de début / délivrance` | `licence_start_date` |
| `Date de fin / paiement annuel` | `licence_end_date` |

**Jamais repris du payload** : `status`, `is_blacklisted`, `returned_at` ni la
réservation du commercial — état applicatif qu'une resynchronisation ne doit
pas écraser.

**Horodatage** : `clients.created_at` **et** `clients.updated_at` sont des
colonnes `timestamp` maintenues par Eloquent (le modèle ne désactive plus
`UPDATED_AT`). La migration `2026_09_25_000003` ajoute `updated_at` et
l'initialise à `created_at` sur les lignes existantes ; depuis, toute
modification d'un client le rafraîchit. `updated_at` est exposé dans les
réponses clients (listes commerciale/admin + détail) et accepté comme
valeur de `sort_by`. Aucun des deux horodatages ne vient du payload.

**Import** : `ClientsFromJsonSeeder` (`php artisan db:seed
--class=ClientsFromJsonSeeder`) remplace **tous** les clients par un export
JSON (`database/data/clients.json`, surchargeable avec `CLIENTS_JSON=…`) ;
`categories` reprend les libellés de `authorized_categories` et
`licence_propre` passe à vrai dès qu'un numéro propre est fourni. Les lignes
enfantsées (`reservations`, `rappels`, `notes`) partent en cascade. Ce
seeder n'est **pas** appelé par `DatabaseSeeder` : il se lance à la main.

**Conservés inchangés** (existaient avant l'alignement) :

* `id` UUID reste la clé primaire (FK `reservations`, `rappels`, `notes`) ;
  `licence_propre` reste un **booléen** (« Licence propre : Oui / Non ») ;
* `surety_company` (string unique) reste affiché ; `cautionnement_compagnie`
  (tableau) est ajouté à côté.

**Contraintes** :

* **NOT NULL** (`licence`, `intervenant`, `neq`, `telephone`, `respondents`)
  appliquées au niveau du futur webhook n8n, **pas en base** — les lignes
  existantes restent valides ;
* `licence_propre_numero` est **UNIQUE** : clé d'upsert du futur webhook.

**UI** : fiche client (`ClientDetailsTab`) affiche les blocs « Licence » et
« Cautionnement » et normalise les deux formats de répondants (chaîne n8n ou
`{name, role}`), commercial **et** admin (`CommercialAdminController`).
