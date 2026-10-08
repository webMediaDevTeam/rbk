# Rapport — Changements de statut client & fonctionnement des notes

> Rapport d'analyse (lecture seule, aucun changement de comportement).
> Source de vérité métier : `docs/RULES.md` (§2 Statuts, §3 Workflow, §4 Rappels,
> §6 Notes). Modèle : `docs/models.puml`.
> Chemins cités : `backend/app/...` et `frontend/src/...`.

---

## 1. Périmètre

Analyse complète du cycle de vie des statuts d'un prospect — les issues d'appel
**Oui**, **Non**, **BV**, **À rappeler**, **Info**, **Double** — et du système de
**notes** (journal d'interaction) qui les trace.

Trois dimensions sont couvertes :
1. le **modèle** (constantes, colonnes, scopes) ;
2. le **moteur** (`CallWorkflowService` + endpoints) ;
3. l'**affichage** (badges, fiche client, frise de notes) et les **tests** qui
   verrouillent le contrat.

---

## 2. Modèle de statuts

### 2.1 Les trois colonnes qui portent l'état

| Colonne | Table | Valeurs | Rôle |
|---|---|---|---|
| `status` | `clients` | `AVAILABLE`, `RESERVED`, `UNAVAILABLE`, `BLACKLISTED`, `CONFIRMED`, `DOUBLE`, `INFO` (`Client::STATUSES`) | état **stocké** du client |
| `status` | `reservations` | `PENDING`, `YES`, `NO`, `BV_VOICEMAIL`, `CALL_BACK`, `DOUBLE`, `INFO` (`Reservation::STATUSES`) | état de la **réservation courante** |
| `type` | `notes` | les 7 ci-dessus + `RESERVED`, `RETURNED_TO_AVAILABLE`, `BLACKLISTED`, `NOTE` (`Note::TYPES`) | **trace** écrite à chaque transition |

Deux valeurs sont **dérivées, jamais stockées** :
* `Client::STATUS_IN_PROGRESS` (« En traitement ») = réservation `BV_VOICEMAIL` ou `CALL_BACK` ;
* `Client::STATUS_SANS_TELEPHONE` (« Sans tel.. ») = `phone` vide.

`Reservation::STATUS_REALIZED` existe mais **n'est jamais émis** (déclaration
réservée, cf. `models.puml`).

### 2.2 Régime « tenu » (`HELD_STATUSES`)

`Client::HELD_STATUSES = [RESERVED, CONFIRMED, DOUBLE, INFO]` — un client *tenu* :

* garde son numéro visible **pour son seul titulaire** (`canSeePhone()`) ;
* expose `my_reservation` → le bouton **« Suite appel »** reste actif ;
* compte comme « réservé » et « en cours de traitement » dans les KPI
  (`ProspectOverviewController`) ;
* est exclu du pool de réservation (`POST clients/reserver`).

### 2.3 Points courants

* `clients.current_reservation_id` / `current_comercial_id` sont les **seuls**
  pointseurs d'affichage ; ils ne sont écrits que par
  `Client::syncCurrentReservation()` (`Client.php:406-430`), appelé par
  `Reservation` à chaque `saved`/`deleted` (`Reservation.php:118-122`).
* `Client::displayStatus()` (`Client.php:459-472`) ne qualifie que `RESERVED`
  via la dernière réservation ; `DOUBLE`/`INFO` sont renvoyés tels quels.
* **Il n'existe pas de machine à états** dans le modèle : aucune méthode de
  transition, la table de transition vit entièrement dans le service (§3).

---

## 3. Les six issues de l'appel

### 3.1 Point d'entrée unique

```
POST /api/v1/clients/{clientId}/outcome     (CheckRole:COMERCIAL)
└─ OutcomeController::store()               backend/app/Http/Controllers/Api/V1/Commercial/OutcomeController.php:16
   └─ CallWorkflowService::apply()          backend/app/Services/CallWorkflowService.php:80
```

`apply()` applique **une transaction unique** et, dans cet ordre :
1. vérifie que l'issue est dans la liste blanche `handled` (sinon `InvalidArgumentException` → 422, **avant toute écriture**) ;
2. écrit **la note** (`client_id`, `reservation_id`, `sender_id` = employé, `type`) ;
3. applique le `match` de la transition (§3.2) ;
4. renvoie `{message, client_status, blacklisted}` (`result()`).

### 3.2 Table de correspondance outcome → statut

| `outcome` | `notes.type` | `clients.status` | `returned_at` | `reservations.status` | Compteur | Rappel | Garde |
|---|---|---|---|---|---|---|---|
| `YES` (Oui) | `YES` | `CONFIRMED` | `null` | `YES` | — | annulé | réservation **obligatoire → 422** |
| `NO` (Non) | `NO` | `UNAVAILABLE` | `now + 3 mois`¹ | `NO` | — | annulé | réservation **ou** historique d'appel (sinon **403**) |
| `BV` | `BV` | `RESERVED`² | inchangé | `BV_VOICEMAIL` | `bv_count++` | annulé, puis **recréé `now+3 j`** | réservation obligatoire (422) |
| `CALL_BACK` (À rappeler) | `CALL_BACK` | `RESERVED`² | inchangé | `CALL_BACK` | `injoinable_count++` | annulé, puis **recréé à `recall_at`** | réservation obligatoire (422) + `recall_at` futur (422) |
| `DOUBLE` (Double) | `DOUBLE` | **`DOUBLE`** | `null` | **`DOUBLE`** | aucun | **annulé** | réservation obligatoire (422) |
| `INFO` (Info) | `INFO` | **`INFO`** | `null` | **`INFO`** | aucun | **annulé** | réservation obligatoire (422) |

¹ durée lue dans `config('rules.non_block_months')` (défaut 3).
² affiché **« En traitement »** (`IN_PROGRESS`, dérivé).

### 3.3 Les 3 issues « à filet de sécurité »

* **3ᵉ BV** (`BV_ATTEMPTS_LIMIT = 3`) → `blockTemporarily()` : client
  `UNAVAILABLE`, `returned_at = now + 21 j`, réservation **conservée**, rappel
  annulé, note auto. Déclenché soit par `handleRecall()` à la 3ᵉ issue, soit par
  le cron `clients:process-timeouts` (`handleRecallExpired()`).
* **Non par tous les employés** → auto-blacklist : quand **tous** les `COMERCIAL`
  actifs ont une note `NO` distincte sur le client, `shouldAutoBlacklist()`
  écrit une note système `BLACKLISTED` (« Tous les employés actifs ont répondu
  NON. ») puis bascule en liste noire.
* **À rappeler** : `CALL_BACK_ATTEMPTS_LIMIT = null` → **jamais de blocage**, le
  rappel est re-planifié à la date choisie, sans limite.

### 3.4 Double / Info — le régime « Réservé »

Issues ajoutées pour les prospects **toujours tenus** par leur employé :

* statut client **et** réservation passent à `DOUBLE` / `INFO` ensemble ;
* `returned_at` vidé (plus de compte à rebours) ;
* le rappel en cours (BV / à rappeler) est **annulé** ;
* **aucun compteur** n'est incrémenté (l'issue n'est pas un traitement abouti) ;
* visibilité **titulaire uniquement** dans la Grande liste
  (`ClientSearchService::applyFilters()`, branche `status ∈ {DOUBLE,INFO} AND
  current_comercial_id = connecté`) ;
* **jamais réservable une seconde fois** (la branche n'existe pas quand
  `$heldByUserId = null`, chemin réservation de lot) ;
* le bouton « Suite appel » reste affiché (client dans `HELD_STATUSES`).

Test de référence : `backend/tests/Feature/DoubleInfoStatusTest.php`.

### 3.5 Autres transitions de statut (hors endpoint `outcome`)

| Écriture | Source | Effet |
|---|---|---|
| Réservation d'un lot | `ReservationService::reserve()` (`:93-117`) | client `AVAILABLE → RESERVED`, réservation `PENDING`, note `RESERVED` (système) — verrou `lockForUpdate`, refus si statut ≠ `AVAILABLE` (409 `already_reserved`) |
| Libération d'un lot | `ReservationController::releasePending()` (`:220`) | réservations `PENDING` supprimées, rappels supprimés, client `AVAILABLE`, note `RETURNED_TO_AVAILABLE` — **privilège** `has_permission` |
| Déblocage admin | `AdminController::debloquerClient()` (`:30`) | **toutes** les réservations + rappels supprimés, client `AVAILABLE`, note système |
| Réactivation cron | `ProcessClientReactivation` | `returned_at` échu → `AVAILABLE` + note système |
| Liste noire (commercial) | `ClientController::blacklist()` (`:120`) | `BLACKLISTED` + `is_blacklisted`, **tous** les rappels annulés, réservations conservées — privilège requis |
| Liste noire (admin) | `CommercialAdminController::blacklist()` (`:456`) | idem, sans privilège |
| Import n8n | `ClientImportService` | création → `AVAILABLE` (`:172`) ; `applyBlacklist()` (`:790`) ; indisponibilité téléphone → `UNAVAILABLE +3 mois` (`:1073`) ; fausse réservation `NO` sans effet client (`:1442`) |

---

## 4. Notes — fonctionnement

### 4.1 Règles

* **Journal unique** par client, mêlant *événements* (statuts) et *commentaires*.
* `sender_id` est un **texte** : UUID de l'émetteur **ou** `SYSTEM` (pas de FK) ;
  `Client::notes()` triées `created_at desc`.
* Limite **8 mots** par note (`LimitsNoteWords`), **dérégée pour `SYSTEM`**.
* **Immuabilité** : seules les notes `NOTE` sont supprimables ; un événement
  (`YES`, `NO`, `BV`, `CALL_BACK`, `DOUBLE`, `INFO`, `RESERVED`,
  `BLACKLISTED`, `RETURNED_TO_AVAILABLE`) renvoie 422 (`NoteController::destroy`).
* `Note::CALL_TYPES = [YES, NO, BV, CALL_BACK, DOUBLE, INFO]` = ce qui compte
  comme « traité » dans les KPI et pour l'auto-blacklist ; `RESERVED` en est
  exclu.
* Liens : `client_id` (FK cascade), `reservation_id` (nullable), `call_log_id`
  (note d'appel sortant).

### 4.2 API des notes

| Verbe | Route | Rôle | Particularité |
|---|---|---|---|
| GET | `clients/{clientId}/notes` | COMERCIAL | ownership = **une réservation quelconque** du client lui appartenant, sinon **404** |
| POST | `notes` | COMERCIAL | `type` **forcé à `NOTE`** — impossible de créer un événement par HTTP |
| DELETE | `notes/{id}` | COMERCIAL (auteur) | événements → 422 |

L'admin lit le journal via `GET commercials/clients/{id}` (`CommercialAdminController:393-403`).

### 4.3 Côté interface

* **Modale « Suite appel »** (`ClientDetail/components/ActionModal.jsx` +
  `useActionModal.js`) : grille de **6 boutons** (`YES`, `NO`, `BV`,
  `CALL_BACK`, `DOUBLE`, `INFO`), encart rappel automatique pour `BV`,
  champ `datetime-local` obligatoire pour `CALL_BACK`, note « 8 mots max ».
  N'est affichée que si `!isAdmin && hasReservation`.
* **Frise de notes** (`useNoteTimeline.js`) : config par type (icône, libellé,
  variante de badge), tri desc, dates relatives, émetteur « Système » pour les
  non-`NOTE` sans émetteur, suppression possible uniquement pour `NOTE` sans
  `call_log_id` (et `readOnly` pour l'admin).
* **Badges** : `ClientStatus` (dimension client : Libre / Réservé / Oui / Non /
  BlackList / **Double** / **Info**), `ReservationStatusBadge` (dimension
  réservation : En attente / Oui / Non / BV / À rapp.. / **Double** / **Info**),
  `ProspectStatus` (une seule valeur par ligne, règle de précedence).

---

## 5. Ce que les tests verrouillent

| Fichier | Focal |
|---|---|
| `OutcomeControllerTest` (12) | YES/NO/BV/CALL_BACK, `recall_at` requis/passé/futur, 8 mots → rollback, garde réservation → 422, NO sans rien → 403, rôles |
| `DoubleInfoStatusTest` (13) | DOUBLE/INFO : statut, rappel annulé, 422 sans réservation, labels rejetés, visibilité titulaire seule, jamais réservable 2e fois, KPI, **badges compteur = lignes** |
| `CallWorkflowServiceTest` (25) | 3ᵉ BV → 21 j, rappel échu, blacklist conserve les réservations, cron, note optionnelle |
| `NoteControllerTest` (11) | ownership, types événements rejetés, 8 mots, immuabilité, admin bloqué par `CheckRole` |
| `ReservationWorkflowApiTest` (29) | réservation/libération, `active-count`, déblocage admin, rappels `done` |
| `CurrentReservationStatusBadgesTest` (7) | pointeur courant, filtre admin = valeur affichée, compteurs = lignes |
| `ReleasePermissionTest` (9) | privilège `has_permission` (blacklist / libération) |

**Verrou important** : l'invariant *compteur de badge = lignes rendues après
clic* est testé pour les 10 badges (`CurrentReservationStatusBadgesTest:222`,
`DoubleInfoStatusTest:321`).

---

## 6. Constats & recommandations

### 6.1 Incohérences de code (à arbitrer)

1. **`handleRecall()` ne vide pas `returned_at`** (`CallWorkflowService.php:251`)
   alors que `handleYes/handleNo/handleHeldOutcome` le font. Un client
   `UNAVAILABLE` remis en `RESERVED` garde un compte à rebours résiduel affiché
   sous le badge (« Retour dans Bientôt »).
2. **Aucune vérification `is_blacklisted` ni `reservation->active()`** dans
   `OutcomeController` (`:34-42`) : on peut enchaîner `YES`/`DOUBLE`/`INFO` sur
   une réservation d'un client blacklisté → statut `CONFIRMED`/`DOUBLE` avec
   `is_blacklisted = true`.
3. **Branche morte** : `blockTemporarily()` peut écrire une note
   `TYPE_CALL_BACK` (`:407`), mais `CALL_BACK_ATTEMPTS_LIMIT = null` rend cette
   voie inatteignable.
4. **Branche admin morte** dans `NoteController::destroy` (`:92-100`) : la route
   est fermée `CheckRole:COMERCIAL` (testé explicitement).
5. **Branche UI inatteignable** : l'encart « Réservé par X. Réservation requise »
   et le `disabled={!hasReservation}` de `ActionModal` ne peuvent jamais se
   déclencher (la modale ne s'ouvre que si `hasReservation`) — alors que le cas
   métier « `NO` sans réservation mais avec historique d'appel » est testé côté
   API (`OutcomeControllerTest:356`) et donc **inaccessible depuis l'écran**.
6. **Cohérence notes/historique** : `POST /notes` et `GET .../notes` exigent une
   **réservation**, alors que l'issue `NO` est justifiée par un simple
   historique d'appel. Après une libération de liste (réservations supprimées),
   le commercial peut encore envoyer `NO` mais reçoit **404** sur le journal.
7. `useCreateNote()` / `POST /notes` et `POST reminders/{id}/done` **n'ont aucun
   consommateur UI** : la seule saisie de note réelle est le champ de la modale
   « Suite appel ».

### 6.2 Points de vigilance données

8. **`notes.reservation_id` en `cascadeOnDelete`** (migration
   `2026_09_29_000004`), et la FK n'est créée que **sur MySQL** — jamais donc
   exercée par les tests SQLite. Or `release-pending` et le déblocage admin
   **suppriment** des réservations : en MySQL, toutes les notes rattachées
   (issues `YES/NO/BV/...`) seraient effacées en cascade, contre l'intention
   « historisable ». *À confirmer en lecture seule sur `rbqbot` avant toute
   conclusion.*
9. `by_status` (`GET clients/overview`) **n'a pas de bucket `DOUBLE`/`INFO`**
   alors que `filterByStatuses()` les accepte : la somme des badges reste
   inférieure au total « Tous ». Documenté dans `RULES.md`, mais contre-intuitif.

### 6.3 Écarts de documentation

10. `RULES.md` §3.1/§3.2/§3.3 écrivent `POST clients/{clientId}/outcomes`
    (**pluriel**) ; la route réelle est `outcome`.
11. Seuil BV erroné dans `RULES.md` (l.102, l.666 : « 2 BV → 21 j ») alors que
    `BV_ATTEMPTS_LIMIT = 3` (et que CALL_BACK ne bloque jamais).
12. `RULES.md` §3 (l.218) affirme que YES remet `is_blacklisted` à faux :
    `handleYes()` n'y touche jamais.
13. `RULES.md` §1.1 et §4 sont incomplets/périmés sur `HELD_STATUSES`
    (DOUBLE/INFO y ont accès au téléphone et à « Suite appel »).
14. Des scopes cités par la doc (`scopeProspectList`, `scopeSearchAll`,
    `scopeFilterByStatuses`, …) **n'existent plus** : tout est migré dans
    `ClientSearchService`.
15. Doc de test périmée : `OutcomeControllerTest.php:17-19` énumère 4 issues
    acceptées alors qu'elles sont 6.

### 6.4 Recommandations priorisées

| # | Action | Gain | Effort |
|---|---|---|---|
| 1 | Vérifier (lecture seule) la cascade `notes.reservation_id` sur MySQL, puis passer la FK en `nullOnDelete` si confirmé | évite une **perte de données** d'historique | faible |
| 2 | Vider `returned_at` dans `handleRecall()` | cohérence d'affichage | très faible |
| 3 | Ajouter la garde `is_blacklisted` / réservation active dans `OutcomeController` (ou expliciter le cas voulu + test) | cohérence métier | faible |
| 4 | Supprimer la branche morte `TYPE_CALL_BACK` de `blockTemporarily()` + la branche admin de `NoteController::destroy` (ou rouvrir la route à l'admin) | lisibilité | très faible |
| 5 | Aligner `RULES.md` (pluriel `outcomes`, seuil BV, `HELD_STATUSES`, scopes obsolètes) | doc = code | faible |
| 6 | Ouvrir la lecture du journal sur « historique d'appel » comme pour `NO` | corrige le 404 après libération | moyenne |
| 7 | Rendre accessible depuis l'UI le cas « `NO` sans réservation » (ou fermer l'issue côté API) | ferme la branche inatteignable | moyenne |

---

*Rapport généré le 2026-10-08 à partir du code de la branche courante
(`deabac5`).*
