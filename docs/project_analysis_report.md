# Rapport — Analyse du projet RBK (architecture, liste clients, performance)

> Rapport d'analyse (lecture seule). Méthode : **graphify** (graphe de
> connaissance du dépôt) + lecture du code + mesures **EXPLAIN** sur la base de
> dev MySQL `rbqbot` (45 938 clients, MySQL 8.0.46, lecture seule).

---

## 1. Méthode & fraîcheur du graphe

| Élément | Valeur |
|---|---|
| Graphe | `graphify-out/graph.json` — **2 319 nœuds · 6 357 arêtes · 175 communautés** |
| Extraction | 98 % `EXTRACTED`, 2 % `INFERRED`, 0 % `AMBIGUOUS` |
| Rapport d'audit | `graphify-out/GRAPH_REPORT.md` · visualisation `graphify-out/graph.html` |
| Commit du graphe | `7c7ad7e` — **antérieur à `HEAD` (`deabac5`)** : le graphe ne contient pas le travail récent (statuts `DOUBLE`/`INFO`, sources, credentials RingCentral par entreprise, joueur d'enregistrement) et contient encore la page `CallLogTest/index.jsx` **supprimée ce jour**. |
| Rafraîchissement | `graphify update .` (aucun coût de token, extraction AST déterministe) |

> Le graphe sert ici de **carte d'architecture** (communautés = zones
> cohérentes du code). Les analyses de performance, elles, reposent sur le code
> courant et sur des mesures MySQL réelles.

**God nodes** (nœuds les plus connectés) — l'ossature du projet :

| Nœud | Arêtes | Rôle |
|---|---|---|
| `Client` | 198 | entité centrale (métier + UI + API) |
| `User` | 172 | authentification, rôles, employés |
| `cn()` / `lucide-react` | 78 / 71 | socle UI partagé |
| `Reservation` | 65 | workflow d'appel |
| `Note` | 61 | journal d'interaction |
| `ClientImportService` | 59 | intégration n8n / import |
| `Rappel` | 49 | planification des rappels |
| `RingCentralService` | 47 | téléphonie |

Aucun **cycle d'import** détecté (bilan `graphify`).

---

## 2. Architecture

### 2.1 Vue d'ensemble

```
                       n8n / scraper (externe)
                              │  X-Api-Key
                              ▼
┌──────────────┐   HTTP/JSON  ┌──────────────────────────────────────┐
│  Frontend    │ ───────────► │  Laravel 11 — /api/v1                │
│  React 18    │ ◄─────────── │  routes: shared|superAdmin|admin|    │
│  Vite · RQ   │  Bearer      │         entreprise|commercial        │
└──────┬───────┘              └───────────────┬──────────────────────┘
       │                                      │
       │ wavesurfer.js                        │ Eloquent
       ▼                                      ▼
  Lecteur d'enregistrement          MySQL `rbqbot` (45 938 clients)
                                     ▲
                                     │ lecture seule (call logs)
                              RingCentral REST API
                                     ▲
                                     │ OAuth2 CC
                              RingCentralService (scoped par entreprise)
```

### 2.2 Frontend (`frontend/src`)

* **Routing** : `App.jsx` (navigation par rôle `ROLE_NAV`) → `routes/AppRoutes.jsx`
  (`ProtectedRoute` = miroir des middleware `CheckRole` backend).
* **État** : React Query partout (`@tanstack/react-query`), aucun store global
  (le store Zustand `useClientStore.js` a été retiré — plus référencé).
* **API** : `api/client.js` (instance Axios, `baseURL = VITE_API_URL/api/v1`,
  `Authorization: Bearer`), wrappers par domaine (`shared.api.js`,
  `commercial.api.js`, `outcomes.api.js`…).
* **Socle UI** : composants `components/ui/*` (shadcn/Tailwind), `Badge`,
  `KpiPill`, `ClientStatus`/`ProspectStatus` (statuts), `RecordingPlayer`.
* **Zones** : `pages/comercial/*` (prospects, mes listes, rappels, BV),
  `pages/shared/*` (fiche client partagée entre rôles, listes gestionnaires),
  `pages/superAdmin/*` (admins).
* **Nettoyage récent** : page `CallLogTest` (console RingCentral), stores,
  `RoleBasedRoute`, `use-dialog-state`, `utils/constants|helpers`, primitives
  shadcn inutilisées (`tabs`, `form`, `calendar`, `collapsible`) et fichiers
  racine parasites (`d`, `@php`) ont été supprimés.

### 2.3 Backend (`backend`)

* **Contrôleurs** groupés par rôle (`Api/V1/{Commercial,Shared,Admin,Entreprise,SuperAdmin}`) —
  le RBAC est porté par les **routes** (`CheckRole`, `CheckPermission`), pas par
  les contrôleurs.
* **Services** (la logique métier vit là, pas dans les contrôleurs) :
  `CallWorkflowService` (transitions de statut), `ReservationService`
  (verrous de réservation), `ClientSearchService` (filtres/recherche/badges),
  `ClientImportService` (import n8n, ~1 500 lignes), `RingCentralService` +
  `RingCentralSyncService`.
* **Observateurs** : `ClientObserver` (colonnes dérivées `phone_normalized`,
  `simple_name`), `Reservation` (pointeur `current_*` sur le client).
* **Cron** : `clients:process-timeouts` (rappels échus), `clients:reactivate`
  (retour à `AVAILABLE`).

### 2.4 Modèle de données

```
users (rôle: SUPER_ADMIN|ADMIN|COMERCIAL) ──┐
                                            │ current_comercial_id
clients (45 938 lignes, 122 MB, JSON) ◄─────┤
   ▲        ▲        ▲                      │
   │        │        └── current_reservation_id
notes    reservations ── rappels
(15 423)  (103)         (0 en dev)
   │
   └── call_logs / call_recordings (intégration RingCentral)
```

* `clients` porte trois **colonnes JSON** (`respondents`, `categories`,
  `authorized_categories`) qui gonflent la table (122 MB pour 46 k lignes) et
  sont interrogées en `LIKE` (voir §4).
* `reservations` est **toujours jointe** via le pointeur `clients.current_reservation_id`
  (pas de scan de l'historique) — très bon choix de conception.
* `notes` a un index composite `(client_id, created_at)` adapté à la frise.

---

## 3. Parcours « affichage de la liste clients »

Deux listes distinctes partagent le même moteur de recherche.

### 3.1 Grande liste **commerciale** — `GET /clients`

```
ProspectList (frontend)
 └─ useProspectList → commercial.api.js
     └─ Commercial/ClientController::index()          ClientController.php:22
         ├─ ClientSearchService::applyFilters($q, $input, connecté)   :452
         │    ├─ facettes (municipalité / catégorie / région)
         │    ├─ exclusions : is_blacklisted = false, phone non vide,
         │    │   (AVAILABLE ∧ aucune réservation active) OU (DOUBLE/INFO ∧ titulaire)
         │    └─ tri (whitelist SORTABLE)
         ├─ paginate(per_page ≤ 20, max 300)
         └─ Client::loadLatestReservations()  → 1 requête groupée ✓
```

Points remarquables : **une seule requête de chargement des réservations**
(pas d'N+1) ; téléphone masqué (`canSeePhone` n'est calculé **que** sur la fiche,
jamais dans la liste).

### 3.2 Grande liste **admin** — `GET /commercials/clients`

```
clientsHistory (frontend)
 └─ useClientsHistory → ProspectKpis (10 badges, sélection unique)
     └─ CommercialAdminController::clients()           :182
         ├─ Client::query()->with(['notes' → sender, 'currentReservation:id,status'])
         ├─ ClientSearchService::search() (si q)
         ├─ applyDisplayStatusFilters(status | reservation_status)
         └─ paginate(per_page ≤ 300)
```

⚠️ La préchargée `notes` charge **toutes les notes de chaque client de la page**
(`with('notes')` sans `limit`) : jusqu'à 300 lignes × l'historique complet, pour
n'utiliser ensuite que `notes->first()`.

### 3.3 Les 10 badges de la colonne « Statut »

`GET clients/overview` → `by_display_status` : `Libre`, `Blacklist`,
`Sans tel..` (dimension **client**) + `Oui`, `Non`, `BV`, `À rapp..`, `Double`,
`Info`, `-` (dimension **réservation courante**).

Chaque compteur est produit **par le scope qui pilote le filtre**
(`ClientSearchService::displayStatusCounts()`), d'où l'invariant vérifié par les
tests : *compteur du badge = lignes rendues après clic*.

---

## 4. Performance : l'affichage de la liste est lent — causes mesurées

### 4.1 Constats chiffrés (MySQL 8.0.46, `rbqbot`, 45 938 clients)

| Requête représentative | `EXPLAIN` | Mesure |
|---|---|---|
| Liste filtrée + tri (`is_blacklisted=0 AND status='AVAILABLE' AND phone≠'' ORDER BY name`) | `type: ALL`, `possible_keys: NULL`, `rows: 45938`, `Extra: Using where; Using filesort` | **plein scan + tri en mémoire/fichiers** |
| Badge « Libre » : `COUNT(*)` avec les mêmes filtres | `Table scan on c` → 53 149 lignes lues | **≈ 51 ms par compteur** |
| Recherche « toutes colonnes » (6 branches `OR … LIKE` + `CAST(JSON AS CHAR) LIKE`) | `Table scan on c` (`cost=6207`) | rapide si beaucoup de correspondances (stop à 20 lignes), **plein scan** sinon |

### 4.2 Causes, par ordre d'impact

**1. `clients` n'a AUCUN index sur les colonnes filtrées ni sur les clés de tri.**
Inventaire réel des index de `clients` : `PRIMARY(id)`,
`current_comercial_id` (FK), `current_reservation_id` (FK),
`licence_propre_numero` (unique), `phone_normalized`, `simple_name`.
Il manque : `status`, `is_blacklisted`, `municipality`, `created_at`,
`administrative_region`, `email`, `phone` (voir §4.4 pour le bon index composite).

**2. Deux index existants sont morts.** `phone_normalized` et `simple_name`
sont **écrits** à chaque sauvegarde par `ClientObserver` et **indexés**
(migration `2026_10_07_000003`), mais **aucune requête ne les lit** :
`ClientSearchService::searchByName()` interroge `name LIKE` et `searchByPhone()`
re-normalise `phone` en SQL (`REPLACE()` imbriqué, `digitsOnlySql`) — expression
**non sargable** qui rend l'index `phone_normalized` inutilisable.

**3. La recherche JSON est non sargable.** `orWhereJsonTextLike()` produit
`CAST(respondents AS CHAR) LIKE '%x%'` sur 3 colonnes × 2 motifs = 6 branches
`OR` qui forcent le plein scan (acceptable sur le volume actuel, bloquant si la
table grandit).

**4. Les compteurs sont recalculés à chaque appel.** `displayStatusCounts()`
exécute **10 `COUNT(*)`** (chaque scan ≈ 51 ms → ≈ 0,5 s), et
`ProspectOverviewController` appelle `count()` **10 fois de plus** (KPI +
`by_status`) : `GET clients/overview` représente donc **~1 s de CPU MySQL par
appel**, rafraîchi côté client toutes les 15 s (`useProspectKpis`, `staleTime`).
C'est la cause principale de la sensation de lenteur sur les listes.

**5. N+1 / requêtes volumineuses.**
* `GET commercials/clients` : préchargée `notes` non bornée (§3.2).
* `GET commercials` (liste des employés) : 3 `COUNT` par commercial en boucle ;
  `dashboard/stats` : 4 requêtes par commercial (`topCommercials()`).
* `reservations` n'a **aucun index sur `status`** (seules les FK), alors que
  `filterByReservationStatuses()` fait `whereIn('current_reservation_id',
  SELECT id FROM reservations WHERE status = …)` — bénin aujourd'hui (103
  lignes), à surveiller.

**6. La table est « lourde ».** 122 MB pour 45 938 lignes (colonnes JSON + 4
`varchar(255)`), donc chaque scan plein déplace beaucoup de pages InnoDB.

### 4.3 Ce qui est déjà bien fait

* Pointeur `current_reservation_id` : la colonne « Statut » n'a **pas** à joindre
  l'historique des réservations.
* `loadLatestReservations()` : une seule requête pour la dernière réservation de
  toute la page (pas d'N+1 côté commercial).
* `notes` indexées `(client_id, created_at)`, `sender_id`, `reservation_id` —
  la frise et l'historique employé sont servis par index.
* `rappels` : index composites `(comercial_id, done_at)` et `(client_id, reminder_date)`.
* Pagination bornée (≤ 300) partout, requêtes regroupées en `whereIn` plutôt
  qu'en boucles PHP.

### 4.4 Plan de remédiation proposé

| # | Action | Effort | Gain attendu |
|---|---|---|---|
| 1 | Index composite **`clients(is_blacklisted, status, municipality)`** (et `(is_blacklisted, status, created_at)` si tri par date) — migration unique, non bloquante | faible | le scan du badge « Libre » (≈ 51 ms) tombe à quelques ms ; idem pour la liste filtrée + `ORDER BY municipality` (disparition du `filesort`) |
| 2 | Utiliser **`simple_name`** dans `searchByName()` (préalable normalisé côté requête) et **`phone_normalized`** dans `searchByPhone()` quand la saisie ne contient que des chiffres | faible | les 2 index existants deviennent utiles ; recherche nom/téléphone sans plein scan |
| 3 | Remplacer les 10 `COUNT(*)` de `displayStatusCounts()` par **un seul `GROUP BY`** (un scan → 10 compteurs) ou un agrégat conditionnel `SUM(CASE WHEN …)` | moyenne | `clients/overview` passe d'environ 1 s à 100-150 ms |
| 4 | Borné la préchargée `notes` côté admin : `with('notes', fn ($q) => $q->limit(1)->orderByDesc('created_at'))` ou chargement différé dans la carte historique | très faible | suppression du principal transfert volumineux de la Grande liste admin |
| 5 | Cache en mémoire (Redis) des compteurs d'overview, invalidé sur écriture de `clients`/`reservations` | moyenne | rafraîchissement des badges sans coût SQL à chaque tick de 15 s |
| 6 | Indexer `reservations(status)` (et `(client_id, status)`) | très faible | prépare la croissance de la table de réservations |
| 7 | Envisager une colonne générée/indexée `search_text` (concat normalisé) ou un index **FULLTEXT** pour la recherche multi-colonnes | élevée | supprime les 6 branches `LIKE` non sargables |
| 8 | Auditer les colonnes JSON : ne charger `respondents`/`categories`/`authorized_categories` que sur la fiche, pas dans la liste (`$hidden` + `select` ciblé) | faible | réduction du poids des réponses et des pages lues |

> ⚠️ Toute migration doit être appliquée avec `php artisan migrate` **uniquement**
> (`AGENTS.md §1`) : jamais `migrate:fresh` / `db:wipe`. Un index sur 46 k lignes
> se crée en ligne (InnoDB), sans interruption.

---

## 5. Dette & risques transverses

| Domaine | Constat | Réf. |
|---|---|---|
| Documentation | `docs/frontend_structure.md` décrivait des fichiers inexistants (partiellement corrigé) ; `docs/external_api.md` est obsolète ; `RULES.md` contient des écarts (pluriel `outcomes`, seuil BV, scopes supprimés) | `docs/public_api_report.md` §6 |
| Sécurité | Logs HTTP contenant mots de passe/OTP/jeton ; aucun rate limiting ; tokens Sanctum immortels ; CORS `*` + credentials | ibid. §7 |
| Intégrité | `notes.reservation_id` en `cascadeOnDelete` (FK MySQL uniquement, **jamais exercée par les tests SQLite**) alors que des endpoints suppriment des réservations | `docs/status_change_report.md` §6.2 |
| Cohérence | Aucune machine à états dans le modèle : toutes les transitions sont dans `CallWorkflowService` (bon emplacement, mais aucune garde centrale de légalité de transition) | ibid. §6.1 |
| Frontend | 27 warnings ESLint de base (`react-hooks/refs`, `set-state-in-effect`), `check:structure` obsolète (vérifie encore le template Vite initial : 133 « @ alias imports ») | `npm run lint`, `npm run check:structure` |
| Tests | 393 tests / 2 540 assertions verts ; SQLite in-memory garanti par `backend/tests/bootstrap.php` (**ne jamais le retirer**, `AGENTS.md §2`) | `php artisan test` |

---

## 6. Plan d'action priorisé (synthèse des 3 rapports)

| Priorité | Action | Rapport |
|---|---|---|
| **P0** | Vérifier la cascade `notes.reservation_id` sur MySQL (perte potentielle d'historique) | status §6.2 |
| **P0** | Masquer les champs sensibles dans `LogHttpTraffic` | api §7.1 |
| **P1** | Index composite `clients(is_blacklisted, status, municipality)` + GROUP BY des 10 compteurs | ce rapport §4.4 |
| **P1** | Rate limiting + expiration des tokens Sanctum | api §7.2/7.3 |
| **P1** | Garde `is_blacklisted` / réservation active dans `OutcomeController` + `returned_at` vidé par `handleRecall()` | status §6.1 |
| **P2** | Utiliser `simple_name` / `phone_normalized` dans la recherche ; borde la préchargée `notes` admin | ce rapport §4.4 |
| **P2** | OpenAPI unique (ou `route:list` archivé) + nettoyage des routes orphelines et des docs obsolètes | api §6/7.10 |
| **P3** | Cache des compteurs, index FULLTEXT, audit des colonnes JSON | ce rapport §4.4 |

---

*Rapport généré le 2026-10-08. Mesures MySQL effectuées en lecture seule sur
`rbqbot` (conformément à `AGENTS.md §1`).*
