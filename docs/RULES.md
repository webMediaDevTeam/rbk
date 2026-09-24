# RBQBot — Règles métier (référence unique)

> Document consolidé : fusion de `permission_and_rules.md` (rôles/permissions) et
> `UDAPTE.md` (workflow d'appel). Ce fichier fait foi ; les deux documents sources
> ont été supprimés.

## 1. Rôles & permissions

* **COMERCIAL** — réserve des lots de prospects, enregistre les issues d'appel
  (OUI / NON / BV / INJOINABLE), consulte ses listes et ses rappels.
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

### Clients (`clients.status`)

| Statut | Signification |
|---|---|
| `AVAILABLE` | Disponible à la réservation (règle `scopeAvailable`) |
| `RESERVED` | Réservé par un commercial (appel en cours : BV / Injoignable / en attente) |
| `SUCCESS` | Confirmé (issue « OUI ») — définitif jusqu'à clôture admin |
| `UNAVAILABLE_TEMP` | Indisponible temporairement pour **tous** les employés ; `returned_at` porte la date de retour |
| `BLACKLISTED` | Liste noire (`is_blacklisted = true`) |

`scopeAvailable()` : `status = AVAILABLE` **et** (`returned_at` null ou passé).

### Réservations (`reservations.status`)

`EN_ATTENT`, `OUI`, `NON`, `BV`, `INJOINABLE`.

* **INJOINABLE s'affiche « À RAPPELER »** dans l'UI (badge dédié).
* Aucune expiration : la colonne `expires_at` a été supprimée. Les réservations
  **actives** sont `EN_ATTENT / OUI / BV / INJOINABLE` **et** le client est
  `RESERVED` ou `SUCCESS` (`Reservation::scopeActive()`).
* Il n'existe **aucune libération manuelle** : plus d'endpoint « release ».
  `SUCCESS` est définitif ; seul le déblocage admin supprime les réservations.

### Issues d'appel (`call_outcomes.outcome`)

`OUI`, `NON`, `BV` (ex-`BOITE_VOCALE`, renommé partout), `INJOINABLE`,
`BLACKLIST`, `UNBLACKLIST`.

## 3. Workflow d'appel (`CallWorkflowService::apply`)

Constantes : `RECALL_DAYS = 3`, `NON_BLOCK_MONTHS = 3`, `TEMP_BLOCK_DAYS = 21`,
`ATTEMPTS_LIMIT = 2`.

* **OUI** → client `SUCCESS`, réservation `OUI`, rappel annulé. La réservation est
  maintenue jusqu'à clôture admin (pas de libération manuelle).
* **NON** → client `UNAVAILABLE_TEMP`, `returned_at = now + 3 mois`, réservation
  `NON`. Puis vérification d'**auto-blacklist** : si *tous les employés actifs*
  ont répondu NON pour ce client, il passe en `BLACKLISTED` (un `CallOutcome`
  `BLACKLIST` est journalisé : « Tous les employés actifs ont répondu NON. »).
* **BV** → le client reste `RESERVED`, `bv_count++`, rappel automatique à
  **3 jours** (`recall_at = now + 3d`, `rappel_after = 3`, `rappel_type = JOUR`).
  Si `bv_count >= 2` → `UNAVAILABLE_TEMP`, `returned_at = now + 21 jours`,
  rappel annulé.
* **INJOINABLE** → identique à BV avec `injoinable_count` (affiché « À RAPPELER »),
  **mais le rappel est fixé par l'employé** : `recall_at` est **obligatoire**
  (`required_if:outcome,INJOINABLE`, doit être dans le futur) et saisi via un
  champ `datetime-local` dans le modal (aucun select minute/mois). Le délai
  affiché (`rappel_after` / `rappel_type`) est recalculé dans l'unité la plus
  lisible (`MINUTE` / `HEURE` / `JOUR`). Sans saisie (appel direct du service),
  repli sur le rappel automatique à 3 jours.
* **BLACKLIST** → client `BLACKLISTED`, `is_blacklisted = true`, `returned_at = null`.
  La liste noire **ne supprime pas** les réservations (elles deviennent inactives
  via le statut client).

La note est **optionnelle sur toutes les issues** (y compris BLACKLIST).

## 4. Rappels (« Suite appel »)

* Le bouton **« Suite appel »** n'apparaît que si le client est **réservé par le
  commercial connecté** (`my_reservation` non nul, réservation active + client
  `RESERVED`/`SUCCESS`) et que l'utilisateur n'est pas admin. Sinon il est
  **masqué** (l'admin n'a jamais ce bouton).

* Rappel **BV : fixe et 100 % automatique — 3 jours**, aucune saisie utilisateur
  (les options SEMAINE / MOIS et la saisie de durée ont été supprimées).
* Rappel **INJOINABLE : datetime libre** choisie dans le modal « Suite appel »
  (champ `datetime-local`, obligatoire, dans le futur).
* Rappel expiré **sans action** (`clients:process-timeouts`, toutes les 10 min) :
  le compteur correspondant est incrémenté, `recall_at` est vidé, **la réservation
  n'est jamais supprimée**. Si compteur >= 2 → client `UNAVAILABLE_TEMP`
  21 jours ; sinon le client réapparaît dans les listes (à rappeler manuellement).
* **Deux pages séparées** (`GET reminders?type=`) :
  * **« Rappels »** (`/reminders`, défaut `type=INJOINABLE`) : les réservations
    `INJOINABLE` avec `recall_at` non nul ;
  * **« Auto-rappels »** (`/auto-rappels`, `type=BV`) : les réservations `BV`
    avec `recall_at` non nul.

## 5. Réactivation

* `clients:reactivate` (horodaté **chaque heure**) : les clients
  `UNAVAILABLE_TEMP` dont `returned_at <= now` repassent `AVAILABLE`
  (`returned_at = null`) — **pour tous les employés**.
* Après un NON, le retour à 3 mois remet donc le client disponible **pour tout le
  monde** (l'ancienne règle « sauf ceux qui l'ont réservé » ne s'applique plus :
  la réservation NON n'est pas active).

## 6. Notes

* `Note.content` et `CallOutcome.note` : **8 mots maximum** à la création et à
  l'édition (`LimitsNoteWords`, `ValidationException` en français).
  Les notes legacy plus longues restent affichables (limite appliquée au save).
* Notes optionnelles sur **toutes** les issues d'appel.

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
  * couleur de fond (*trail row*) sur les lignes de suivi (`BV` / `INJOINABLE`
    sans rappel planifié).
* **Page « Mes listes » (tableau)** — compteurs et employé calculés par le
  serveur (`GET reservation-groups`), affichés automatiquement :
  * `Clients` = `reservations as clients_count` (réservations de la liste),
    `Demandé` = `total` ;
  * `Traités` = statuts `OUI + NON + BV + INJOINABLE`, `Restant` = `EN_ATTENT`
    (les deux retombent sur `Clients`) ;
  * colonnes `OUI` / `NON` / `BV` / `Injoinable` (compte par statut) ;
  * `Employé` = **jointure** sur `users` (`first_name + last_name`, repli sur
    `email`) et `Créé le` = `created_at` du groupe.

## 8. Recherche multi-critères (F-22)

Sur **Prospects (commercial)** et **Prospect list (admin)** :

* texte : nom, **entreprise** (`rbq_data->entreprise_name`), email, téléphone,
  NEQ, municipalité, licence, **répondants** (JSON), **catégorie**
  (colonnes `categories` / `categories_id`) ;
* **liste « Liste de tous les prospects »** — recherche étendue à **toutes les
  colonnes affichées** : nom d'entreprise, NEQ, numéro de licence
  (`licence_number`), licence propre (`licence_propre_numero`), répondants
  (`respondents`), catégorie (`categories`) **et** catégories autorisées
  (`authorized_categories`) ;
* filtre **catégorie** (commercial) et **statut** (admin) ;
* **aucun filtre de date** : les champs « Du / Au » et les paramètres
  `date_from` / `date_to` ont été supprimés des deux listes.

## 9. UI — compteurs & badges

* **Sidebar** : badge sur « Mes listes » = nombre de **réservations actives** du
  commercial connecté (`GET reservations/active-count`, actualisé chaque minute) ;
  badge sur « Rappels » = rappels `INJOINABLE` échus ; badge sur
  « Auto-rappels » = rappels `BV` échus (`GET reminders/count?type=`).
* **Prospect list (admin)** : colonne « Retour » avec compte à rebours concis pour
  `UNAVAILABLE_TEMP` (`returned_at`) : « 2 mois 3j », « 18j 04h », « 5h 30m ».
* Badges statut client : Disponible / Réservé / Confirmé / Indisponible / Liste noire.
* Badge réservation : En attente / Confirmé / Refusé / Boîte vocale / **À RAPPELER**.
* **Listes (prospects, historique, listes, employés…)** : colonnes `N°`
  (numéro d'ordre sur la page), `Entreprise`, `Répondants`, `N° de licence`,
  `NEQ`, `Catégorie` dans le tableau **et** dans les cartes mobiles.
* **Lignes par page : 50 / 100 / 200 / 300** (défaut 50) — plafond serveur
  `per_page` relevé de 100 à **300** sur tous les endpoints paginés.

## 10. Endpoints clés

| Méthode | URI | Rôle |
|---|---|---|
| GET | `reservations/active-count` | COMERCIAL |
| POST | `clients/reserver` | COMERCIAL |
| PATCH | `reservation-groups/{id}` | propriétaire ou ADMIN/SUPER_ADMIN |
| GET | `reservation-groups` (compteurs par statut + `employe`) | COMERCIAL |
| GET | `reminders?type=INJOINABLE\|BV`, `reminders/count?type=…` | COMERCIAL |
| POST | `clients/{clientId}/outcome` (`recall_at` requis si `INJOINABLE`) | COMERCIAL |
| POST | `clients/{id}/blacklist` | COMERCIAL |
| POST | `commercials/clients/{id}/blacklist` | ADMIN/SUPER_ADMIN |
| POST | `liste-noire/{id}/debloquer` | ADMIN/SUPER_ADMIN |

Routes supprimées : `release`, `release-pending` (×2), `pending-count`,
`commercials/clients/{id}/unblock`.

## 11. Cron

| Commande | Fréquence | Effet |
|---|---|---|
| `clients:process-timeouts` | 10 min | rappels expirés → compteur++ / blocage 21 j, réservation conservée |
| `clients:reactivate` | horaire | `UNAVAILABLE_TEMP` + `returned_at` passé → `AVAILABLE` |

## 12. Données de licence (payload n8n)

Le schéma `clients` est aligné sur le flux n8n **sans renommer aucune colonne
existante**. Mapping officiel payload → colonne :

| Payload n8n | Colonne `clients` | Type |
|---|---|---|
| `licence_propre` | `licence_propre_numero` | INT UNSIGNED, UNIQUE |
| `numero_licence` | `licence_number` | string |
| `nom_intervenant_entreprise` | `intervenant_name` (+ `rbq_data.name` / `entreprise_name`) | string |
| `statut_licence` | `licence_status` | string (`valide` / `invalide`) |
| `neq` | `neq` | string (stockage texte) |
| `adresse_complete` | `full_address` | text |
| `municipalite` | `municipality` | string |
| `region_administrative` | `administrative_region` | string |
| `telephone` | `phone` | string |
| `repondants[]` | `respondents` (+ `respondent_count`) | JSON |
| `categories_sous_categories[]` | `authorized_categories` (+ `categories`, `categories_id`) | JSON |
| `cautionnement_compagnie[]` | `cautionnement_compagnie` | JSON |
| `montant_caution` | `surety_amount` | DECIMAL(12,2) |
| `date_debut_delivrance` | `licence_start_date` | DATE |
| `date_fin_paiement_annuel` | `licence_end_date` | DATE |

**Conservés inchangés** (existaient avant l'alignement) :

* `id` UUID reste la clé primaire (FK `reservations`, `call_outcomes`, `notes`) ;
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
