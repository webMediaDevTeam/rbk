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

### 1.1 Visibilité du numéro de téléphone

Le `phone` d'un client est une **donnée sensible** : il n'est envoyé par l'API
que si le connecté a le droit de le voir.

| Qui | Condition | `phone` |
|---|---|---|
| ADMIN / SUPER_ADMIN | toujours | envoyé |
| COMMERCIAL | client `RESERVED` / `CONFIRMED` **et** la dernière réservation est à son nom | envoyé |
| COMMERCIAL | client `AVAILABLE` (y compris revenu disponible après un NO / une 3e BV) | **absent** |
| COMMERCIAL | client `RESERVED` par **un autre** commercial | **absent** |
| COMMERCIAL | client `UNAVAILABLE` / `BLACKLISTED` | **absent** |

* La clé est **omise** de la réponse (pas `null`) : impossible de la lire dans
  le payload ni dans les outils de développement.
* Implémentation : `ClientController::canSeePhone()` (détail) et
  `ClientController::mine()` (« mes clients ») ; `Client::loadLatestReservations()`
  précharge désormais `comercial_id` pour éviter une requête par ligne.
* Côté UI, l'absence de `phone` masque la ligne « Téléphone » de l'onglet
  Détails **et** le numéro + bouton « copier » de l'en-tête.
* La liste des prospects (`GET clients`) ne contient **jamais** de numéro : ces
  clients sont `AVAILABLE`, donc sans réservation courante.

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

**Invariant badge ⇄ lignes** : un client **ne peut pas** être `AVAILABLE` avec
un `returned_at` **futur**. Le badge « Disponible » (`by_status`, statut brut,
`ProspectOverviewController`) compterait alors un prospect que la page n'affiche
**pas** (ex. badge 4 / liste 1). L'état incohérent (reliquat de test) se
normalise en **`UNAVAILABLE`**, compte à rebours conservé :

```sql
UPDATE clients SET status='UNAVAILABLE' WHERE status='AVAILABLE' AND returned_at > NOW();
```

Un blocage (NO : 3 mois, 2e BV : 21 j) écrit toujours `UNAVAILABLE` +
`returned_at`, et la réactivation (`clients:reactivate`, `debloquer`) vide
`toujours` `returned_at` en repassant en `AVAILABLE`.

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
`BV_ATTEMPTS_LIMIT = 3`, `CALL_BACK_ATTEMPTS_LIMIT = null` (pas de limite),
`AUTO_BLOCK_DESCRIPTION = 'Auto indisponible après 3 BV, retour 21 j'`.

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
  `3 JOUR`). Si `bv_count >= 3` (3e voicemail) → `UNAVAILABLE`,
  `returned_at = now + 21 jours`, rappel annulé, **réservation conservée**, et
  une **note automatique** `type = BV` est journalisée (émetteur = l'employé
  qui a fait passer la réservation en `BV`, pas `SYSTEM`).
* **CALL_BACK** (affiché « À rappeler ») → `injoinable_count++` et rappel
  planifié, **mais sans limite** : le commercial peut enchaîner autant de
  rappels qu'il veut, le client n'est **jamais** bloqué
  (`CALL_BACK_ATTEMPTS_LIMIT = null`, y compris à l'expiration d'un rappel).
  Le rappel est **fixé par l'employé** : `recall_at`
  est **obligatoire** (`required_if:outcome,CALL_BACK`, doit être dans le futur)
  et saisi via un champ `datetime-local` dans le modal (aucun select
  minute/mois). Le délai affiché (`recall_after` / `recall_unit`) est
  recalculé dans l'unité la plus lisible (`MINUTE` / `HEURE` / `JOUR`). Sans
  saisie (appel direct du service), repli sur le rappel automatique à 3 jours.
* **BLACKLISTED** → client `BLACKLISTED`, `is_blacklisted = true`,
  `returned_at = null`. La liste noire **ne supprime pas** les réservations
  (elles deviennent inactives via le statut client).

À chaque écriture : création d'une ligne `notes` (événement, `sender_id` =
l'employé, `description` = note saisie, `reservation_id` = réservation
concernée) **puis** mise à jour de la réservation/croisement des compteurs,
le tout dans une transaction. Un événement inconnu est rejeté **avant** toute
écriture (`InvalidArgumentException`).

La note est **optionnelle sur toutes les issues** (y compris BLACKLISTED) :
laissée vide, elle est stockée à **`null`** (jamais un texte par défaut).

### 3.1 Cas 2 — « OUI » (réservation → `YES`)

`POST clients/{clientId}/outcomes` avec `{outcome: "YES", note?}`
(`Note::TYPE_YES`, `OutcomeController` → `CallWorkflowService::handleYes`) :

1. **Déclencheur** : l'employé passe la réservation de ce client en `YES` ;
   une réservation de cet employé est **obligatoire** (sinon `422` : « Vous
   devez réserver ce client avant de changer son statut. »).
2. **Réservation** : `status = YES`, rappel planifié annulé.
3. **Client** : `status = CONFIRMED`, `returned_at` vidé.
4. **Note** : `type = YES`, `description` = saisie de l'employé (`null` si
   vide), `sender_id` = **identifiant de l'employé** (`SYSTEM` n'est jamais
   utilisé ici), `reservation_id` = la réservation passée en `YES`.

### 3.2 Cas 3 — « NON » (réservation → `NO`)

`POST clients/{clientId}/outcomes` avec `{outcome: "NO", note?}`
(`Note::TYPE_NO`, `CallWorkflowService::handleNo`) :

1. **Déclencheur** : l'employé passe la réservation de ce client en `NO`.
   Contrairement à `YES`, `NO` est accepté **sans** réservation si cet
   employé a déjà un historique d'appel sur le client (sinon `403` : « Vous
   devez avoir une réservation ou un historique d'appel pour ce client. ») :
   dans ce cas, le statut de réservation n'a rien à changer.
2. **Réservation** : `status = NO`, rappel planifié annulé.
3. **Client** : `status = UNAVAILABLE`, `returned_at = now + 3 mois`
   (`CallWorkflowService::NON_BLOCK_MONTHS = 3`).
4. **Note** : `type = NO`, `description` = saisie de l'employé (`null` si
   vide), `sender_id` = **identifiant de l'employé**, `reservation_id` = la
   réservation passée en `NO`.
5. **Après coup** : auto-blacklist (§1) — si *tous* les employés actifs ont
   répondu NO pour ce client, un événement `BLACKLISTED` est journalisé et le
   client passe `BLACKLISTED` (`returned_at` remis à `null`, ce qui annule
   l'expiration de 3 mois).

### 3.3 Cas 4 — « BV » (réservation → `BV_VOICEMAIL`)

`POST clients/{clientId}/outcomes` avec `{outcome: "BV", note?}`
(`Note::TYPE_BV`, `CallWorkflowService::handleRecall`) :

1. **Déclencheur** : l'employé passe la réservation de ce client en `BV` ; une
   **réservation de cet employé est obligatoire** (sinon `422`).
2. **Réservation** : `status = BV_VOICEMAIL`, compteur `bv_count++`.
3. **Client** : `status = RESERVED` (valeur stockée) → **`display_status =
   IN_PROGRESS` (« En cours de traitement »)**, valeur **dérivée** et jamais
   stockée (§2) : `IN_PROGRESS` ne figure pas dans l'énumération
   `clients.status` du modèle (docs/models.puml), c'est la qualification d'un
   client `RESERVED` dont la dernière réservation est `BV_VOICEMAIL` ou
   `CALL_BACK`.
4. **Rappel automatique** : une ligne `rappels` est créée (`client_id`,
   `comercial_id`, `reservation_id`, `reminder_date = now + 3 jours`,
   `CallWorkflowService::RECALL_DAYS = 3`), après annulation d'un éventuel
   rappel précédent de la même réservation (un seul à la fois).
5. **Note** : `type = BV`, `description` = saisie de l'employé (`null` si
   vide), `sender_id` = **identifiant de l'employé**, `reservation_id` = la
   réservation passée en `BV_VOICEMAIL`.
6. **3e tentative** (condition imbriquée) : si `bv_count >= 3`
   (`BV_ATTEMPTS_LIMIT`), le client passe `UNAVAILABLE` avec
   `returned_at = now + 21 jours` (`TEMP_BLOCK_DAYS`), la réservation reste
   `BV_VOICEMAIL` (**jamais supprimée**), le rappel est **annulé** (traitement
   manuel depuis « Rappels » / « Auto-rappels ») et une **seconde note
   automatique** est journalisée : `type = BV`,
   `sender_id` = l'employé qui a fait passer la réservation en `BV`,
   `reservation_id` = cette réservation,
   `description` = « Auto indisponible après 3 BV, retour 21 j ». Même
   traitement lorsqu'un **rappel expiré** (cron `clients:process-timeouts`)
   porte le compteur au 3e essai : `handleRecallExpired()` appelle le même
   `blockTemporarily()`, la note reste émise par l'employé concerné.

### 3.4 Cas 6 — mise en liste noire manuelle

Deux déclencheurs, **le même chemin** (`CallWorkflowService::apply` avec
`Note::TYPE_BLACKLISTED`, dans une transaction, sans réservation requise) :

* `POST clients/{clientId}/blacklist` `{note?}` (commercial connecté) ;
* `POST commercials/clients/{id}/blacklist` `{note?}` (admin / super admin).

1. **Déclencheur** : l'utilisateur blacklist le client à la main depuis la
   fiche (bouton « Liste noire »), motif **optionnel**.
2. **Client** : `status = BLACKLISTED`, `is_blacklisted = true`,
   `returned_at = null` — restriction **permanente** : le cron
   `clients:reactivate` ne réactive que les `UNAVAILABLE`, seul l'**unblock
   admin** peut lever la liste noire.
3. **Effets** : rappels du client annulés ; les **réservations sont
   conservées** (elles deviennent inactives via le statut client).
4. **Note** : `type = BLACKLISTED`, `description` = motif de l'utilisateur
   (`null` si vide, 8 mots max), `sender_id` = **l'utilisateur qui a blacklisté**
   (l'admin compris, jamais `SYSTEM`).

⚠️ À ne pas confondre avec l'**auto-blacklist** du cas 3 (§3.2 point 5) :
là, la note `BLACKLISTED` est **système** (« Tous les employés actifs ont
répondu NON. ») et porte sur le client ; ici elle est **humaine** et porte le
motif.

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
  réservation n'est jamais supprimée**. Compteur **BV** >= 3 → client
  `UNAVAILABLE` 21 jours + note automatique `type = BV` de l'employé concerné
  (§3.3) ; sinon le client réapparaît dans les listes (à rappeler
  manuellement). Un compteur **CALL_BACK** n'a **aucun seuil** : le rappel
  expiré est retiré sans bloquer le client. Un rappel portant sur un client
  `BLACKLISTED` est simplement retiré (aucun compteur).
* **Deux pages séparées** (`GET reminders?type=`) :
  * **« Rappels »** (`/reminders`, défaut `type=CALL_BACK`) : les rappels de
    réservations `CALL_BACK` ;
  * **« Auto-rappels »** (`/auto-rappels`, `type=BV`) : les rappels de
    réservations `BV_VOICEMAIL`.
  La réponse livre `recall_at` (= `reminder_date`), `recall_after` /
  `recall_unit` (= `delay()` recalculé) et le statut de la réservation.

## 5. Réactivation

### 5.1 Cas 7 — retour automatique (`returned_at` expiré)

* `clients:reactivate` (horodaté **chaque heure**) : les clients
  `UNAVAILABLE` dont `returned_at <= now` repassent `AVAILABLE`
  (`returned_at = null`) — **pour tous les employés**. Couvre les 3 mois après
  un NO comme les 21 jours après la 3e BV.
* Statut et note sont écrits dans **une seule transaction** (jamais de client
  réactivé sans trace).
* **Note** : `type = RETURNED_TO_AVAILABLE`, `sender_id = 'SYSTEM'`,
  `description` = « Client automatically returned to Available status on
  {YYYY-MM-DD HH:MM:SS} after temporary restriction period. » (texte généré :
  il n'est **pas** soumis à la limite des 8 mots, cf. §6).
* **Réservations : aucune écriture.** Il n'existe pas de statut `REALIZED` dans
  le modèle (`docs/models.puml` : `PENDING | YES | NO | BV_VOICEMAIL |
  CALL_BACK`) ; un client `AVAILABLE` rend déjà toutes ses réservations
  inactives, `Reservation::scopeActive()` exigeant un client `RESERVED` /
  `CONFIRMED`. Le client peut donc être réservé à nouveau, et l'historique des
  listes reste consultable.
* Après un NO, le retour à 3 mois remet donc le client disponible **pour tout
  le monde** (l'ancienne règle « sauf ceux qui l'ont réservé » ne s'applique
  plus : la réservation NO n'est pas active).

### 5.2 Cas 8 — déblocage manuel d'une liste noire

* `POST liste-noire/{id}/debloquer` (admin / super admin uniquement — un
  commercial reçoit `403`) : le client passe `BLACKLISTED` → `AVAILABLE`,
  `is_blacklisted = false`, `returned_at = null`.
* **Rappels supprimés et réservations vidées** (choix documenté : l'unblock est
  la seule opération qui purge l'historique de réservation ; le simple
  blacklisting, lui, ne supprime rien — §3.4).
* **Note** : `type = RETURNED_TO_AVAILABLE`, `sender_id` = l'**admin** qui a
  déblocké, `description` = « Client restauré AVAILABLE par {nom} » (repli sur
  l'email puis l'id si le nom est absent ou trop composé, pour rester sous les
  8 mots de toute saisie humaine).

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
  **Exception** : les descriptions **générées** (`sender_id = SYSTEM`, ex.
  « Réservé par {prénom nom} le {YYYY-MM-DD HH:MM:SS} ») ne sont pas de la saisie humaine et ne
  sont pas comptées (`Note::noteWordsAreLimited()`).
* `Note.reservation_id` (nullable, `2026_09_29_000004`) : rattache une note à
  la réservation qui l'a produite — l'événement `RESERVED` (cas 1) et les
  issues d'appel (`YES` / `NO` / `BV` / `CALL_BACK`, cas 2 et suivants).
* Notes optionnelles sur **toutes** les issues d'appel (`null` si laissé vide).
* **Immuabilité** : seuls les commentaires (`type = NOTE`) sont modifiables /
  supprimables (`DELETE /notes/{id}` rejette les événements du workflow).
* `due_date` et `call_duration_seconds` ont été supprimés : aucune écriture
  possible depuis l'UI.

## 7. Groupes de réservation

### 7.1 Cas 1 — création initiale d'une réservation

`POST clients/reserver` (contrôleur + `ReservationService::reserveClient`) —
pour **chaque** client du lot, dans **une transaction unique** :

1. **Validation** : le client doit **exister** et être `AVAILABLE`
   (re-vérifié **sous verrou** `lockForUpdate` — la sélection du lot n'est
   qu'une pré-sélection). Sinon : **aucune écriture**, conflit renvoyé
   (`code` = `client_not_available` / `client_not_found` / `already_reserved`)
   avec les **compteurs** `data.counts` (`requested` / `candidates` /
   `reserved` / `conflicts`).
2. **Réservation** : `Reservation` liée `client_id` + `comercial_id` +
   `reservation_group_id`, `status = PENDING`.
3. **Client** : `status = RESERVED`, `returned_at` vidé (`null`).
4. **Note système** : `type = RESERVED`, `sender_id = SYSTEM`,
   `reservation_id` = réservation créée,
   `description` = « Réservé par {prénom nom de l'employé} le
   {YYYY-MM-DD HH:MM:SS} » (le nom de l'employé, et **jamais** son `id` ; à
   défaut de nom, son `email`).

**Réponse `201`** : `group`, `requested`, `reserved`, `counts`, `conflicts`,
`reservations`, `clients` (état mis à jour : `id`, `name`, `status`,
`returned_at`, `display_status`) et `notes`.

**Même requête que la page Prospects** : les entrées optionnelles `search`,
`municipality`, `category`, `administrative_region`, `sort_by`, `sort_order`
(identiques à `GET /clients`) passent par `Client::scopeProspectList()`,
scope **partagé** par la page et la réservation — le lot réservé est
exactement ce que l'employé voit à l'écran, dans le même ordre (défaut :
`created_at desc`). Le modal frontend envoie les filtres/tris courants de la
page. S'y ajoutent les règles métier : pas de liste noire, client réellement
disponible, aucune réservation active, et pas de prospect déjà traité en
`NO` / `BV` par cet employé.

**Garde de traitement** (« finir ses listes avant d'en ouvrir une autre ») :
tant que l'employé a au moins **une réservation `PENDING`** (prospects non
traités), `POST clients/reserver` répond **`409`** :

```json
{ "success": false, "error": "unfinished_treatment", "pending": 3, "message": "…" }
```

* ne comptent que les réservations **actives** (`Reservation::scopeActive()` :
  statut `PENDING` / `YES` / `BV_VOICEMAIL` / `CALL_BACK` **et** client encore
  `RESERVED` / `CONFIRMED`) : un client noirci ou libéré par l'admin n'est plus
  traitable et ne bloque donc pas l'employé à vie ;
* `GET reservations/active-count` renvoie en plus `pending` (réservations
  encore `PENDING`) et `can_reserve` (`pending === 0`) ; la page Prospects
  désactive le bouton « Réserver » tant que `can_reserve` est `false` et
  affiche « N prospect(s) à traiter dans vos listes » à côté.

### 7.2 Groupes

* Création : `POST clients/reserver` avec `{count}` — **sans nom** : le champ
  « Nom de la liste » a été retiré du modal ; `group_name` reste accepté en
  optionnel (généré côté serveur : « Liste du JJ/MM/AAAA HH:MM » s'il est vide).
  (durée/expiration supprimées).
* `count` : **200 / 250 / 300 uniquement** (`in:200,250,300`), plus de nombre
  libre ; le modal propose seulement ces 3 boutons.
* Renommage : `PATCH reservation-groups/{id}` `{name}` — propriétaire ou admin.
* **Page liste de réservation (détail)** :
  * édition inline du nom de la liste ;
  * **aucun masquage** : toutes les réservations de la liste sont affichées,
    y compris celles avec rappel planifié (BV / À rappeler) — la date de
    retour est montrée dans la colonne `Rappel` (`Retour le jj/mm/aaaa`),
    l'en-tête rappelle « N rappel(s) planifié(s) » (pages « Rappels » /
    « Auto-rappels »). Les lignes **n'apparaissent plus jamais** « en moins »
    qu'à la colonne `Clients` de « Mes listes » ;
  * **barre de badges de statut de réservation**, de **même structure** que
    `ProspectKpis` (page « Tous les prospects ») : `Tous` → `En attente` →
    `Confirmé` → `Refusé` → `Boîte vocale` → `À rappeler` ; sélection
    **multiple** (`Tous` retire tout), couleur pleine à la sélection,
    **tous les compteurs affichés même à 0** (calculés sur **toutes** les
    lignes de la liste : `Tous` = `clients_count`, `Boîte vocale` = `bv_count`) ;
    **`En attente` est sélectionnée par
    défaut à l'ouverture** et le tableau n'affiche que ces lignes ;
  * **tri** : les lignes **« En attente » (`PENDING`) passent toujours en
    tête**, les autres statuts gardent l'ordre du serveur (`created_at` desc)
    — donc `Confirmé` → `Refusé` → `Boîte vocale` → `À rappeler` ;
  * colonnes `Prospect` / `Téléphone` / `Municipalité` / **`État` = statut de
    réservation** (`ReservationStatusBadge`) et non plus le statut client /
    **`Rappel`** — la colonne `Traitement` a été **supprimée** ;
    **`État` affiche `—` (pas de badge) quand la réservation est `PENDING`**,
    dans le tableau comme dans la carte mobile : la ligne est déjà en gris
    `row-pending` et le badge « En attente » de la barre porte l'info ;
  * lignes **« En attente »** (`PENDING`) : fond gris `row-pending`
    (`--row-highlight` : `#c5c5c5` en clair, `#3f3f46` en sombre) — même
    traitement que la liste courante de « Mes listes ». Le *trail row* gris
    des lignes de suivi (`BV_VOICEMAIL` / `CALL_BACK`)
    reste tel quel.
* **Page « Mes listes » (tableau)** — compteurs et employé calculés par le
  serveur (`GET reservation-groups`), affichés automatiquement. Colonnes
  épurées (badge de statistiques « Listes » et colonnes `Demandé`,
  `Restant`, `Injoinable`, `Employé`, `Créé le` **supprimés**) :
  * `Liste` (ancien `Nom`) = **employé + date de création**, affichés
    dynamiquement depuis la jointure `comercial` + `created_at` — le champ
    `name` sauvegardé n'est plus affiché (il reste modifiable via
    `PATCH reservation-groups/{id}`) ;
  * `Clients` = `reservations as clients_count` ; `État` (ancien `Traités`)
    = `traites_count / clients_count` ;
  * colonnes `OUI` / `NON` / `BV` / `À rappeler` (statut `CALL_BACK`, clé
    JSON historique `injoinable_count`) / `Blacklist` =
    `reservations as blacklist_count` (réservations dont le client a
    `is_blacklisted = true`) ;
  * **liste courante** = la plus récente (1re ligne de la 1re page) : classe
    `row-current` → fond `--row-highlight` (`#c5c5c5` en clair, `#3f3f46` en
    sombre, `styles/theme.css`), texte par défaut pour rester lisible.

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
* **Bouton « Réserver » (page Prospects, commercial)** : désactivé tant que
  l'employé a des réservations encore `PENDING` (prospects non traités), avec
  « N prospect(s) à traiter dans vos listes » à côté du bouton — même règle
  que le serveur (`409 unfinished_treatment`, §7.1) ; le compteur vient de
  `GET reservations/active-count` (`pending` / `can_reserve`) et se rafraîchit
  à chaque changement de réservation.
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
  mobiles, le détail d'une liste et l'historique employé (la fiche client le
  réserve aux **admins**, voir ci-dessous).
* **Fiche client (`/prospects/{id}`) — badge du bandeau, selon le rôle** :
  * `ADMIN` / `SUPER_ADMIN` → `ClientStatus` = **statut client** affiché
    (couleur par statut, « Liste noire » neutre, compte à rebours) ;
  * `COMERCIAL` → `ReservationStatusBadge` = **statut de sa réservation en
    cours** (`client.my_reservation.status` — `En attente` / `Oui` / `Non` /
    `Boîte vocale` / `À rappeler`) ;
  * **client en liste noire → aucun badge** pour le commercial (le bloc est
    retiré complètement, `ClientDetail/index.jsx`) ;
  * pas de réservation active du connecté → `my_reservation = null` → aucun
    badge (`ReservationStatusBadge` rend `null` sans `status`).
* **Filtres par statut** (ancienne « barre Overview KPI ») — barre de
  **badges compacts** (une ligne, `flex-wrap`, hauteur ~32 px) juste au-dessus
  des filtres, sur **Prospects (commercial)** et **Prospect list (admin)**,
  alimentée par `GET clients/overview` (tous rôles, chiffres **globaux**,
  recalculés à chaque appel). Elle contient **exactement 5 badges**, dans
  **cet ordre, identique sur les deux pages** :
  1. *Tous* = `prospects.system` (total des clients) ;
  2. *Disponible*, 3. *Réservé*, 4. *Non disponible*, 5. *Blacklisté* = compteurs
     `by_status`, **toujours affichés, même avec un compte à 0**.
  Le survol d'un badge rappelle sa définition (`title`).

  Ce sont ces badges qui sont **LE filtre de statut** de « Prospect list
  (admin) » (le menu déroulant « Statut » de la barre de filtres a été
  supprimé, ainsi que les anciens badges KPI) :

  * **sélection multiple** — un clic ajoute le statut, un second clic le
    retire ; plusieurs statuts peuvent être actifs à la fois (`aria-pressed`),
    la requête envoie `status=A,B` (un tableau `status[]` est aussi accepté,
    valeurs validées contre `Client::STATUSES`) ;
  * **couleur pleine à la sélection, sans bordure violette** : chaque badge
    garde **sa** couleur de fond — *Tous* `blue-600`, *Disponible*
    `emerald-700`, *Réservé* `amber-700`, *Non disponible* `destructive`,
    *Blacklisté* `--status-badge` (noir en clair / gris en sombre) — texte et
    icône passés en contraste (`activeFg`) ; à l'inactif, pastille neutre
    `bg-card` avec pastille d'icône teintée ;
  * *Tous* retire **toutes** les sélections en un clic (état actif = aucun
    filtre de statut actif) ;
  * `Blacklisté` filtre sur `is_blacklisted` (même source que le compteur
    `by_status`), les autres sur `status` **et** `is_blacklisted = false` :
    une ligne blacklistée reste dans le seau *Blacklisté* même si son `status`
    vaut encore `AVAILABLE` ;
  * chaque compteur a **exactement la même définition** que le filtre serveur
    (`by_status` ↔ `GET commercials/clients?status=`) : le chiffre affiché vaut
    le nombre de lignes renvoyées après clic ;
  * sur **Prospects (commercial)**, le filtre est **figé sur *Disponible*** :
    les 5 badges s'affichent dans le même ordre, *Disponible* est sélectionné
    et **aucun n'est cliquable** (`locked`, curseur interdit, infobulle
    « filtre figé ») — cette liste ne contient que des prospects disponibles
    et n'accepte pas le paramètre `status`.

  Les compteurs sont **globaux** : ils ne suivent pas les filtres recherche /
  municipalité / catégorie / région de la liste.

  Anciens badges KPI **retirés** de l'affichage (les compteurs restent
  produits par `clients/overview`, inutilisés côté UI) : *Prospects*
  (dispo / total), *Réservés*, *Réservés traités*, *Réservés non traités*,
  *Succès / traités*, *En cours / traités*. Rappel des définitions : **traité**
  = au moins une issue d'appel (note `YES` / `NO` / `BV` / `CALL_BACK`) ;
  **succès** = traité et `CONFIRMED` (issue « YES ») ; **en cours** = traité
  mais encore `RESERVED`.
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
`{success, data: {prospects, reserved, processed, by_status}}` et n'est **pas**
caché : les compteurs doivent bouger à chaque réservation et issue d'appel.
`by_status` contient une entrée par statut **courant** (`AVAILABLE` /
`RESERVED` / `CONFIRMED` / `UNAVAILABLE` = clients portant ce `status` et **non**
blacklistés ; `BLACKLISTED` = drapeau `is_blacklisted`, quel que soit le `status`) :
c'est **exactement la définition du filtre `status`** de
`GET commercials/clients` (qui accepte `status=A,B` ou `status[]`, valeurs
validées contre `Client::STATUSES`) — le chiffre affiché sur un badge vaut
donc le nombre de lignes renvoyées après clic. Les statuts historiques hors
`Client::STATUSES` sortent des badges : la somme des 4 badges peut donc
rester inférieure à `prospects.system` (total affiché par le badge *Tous*).

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
