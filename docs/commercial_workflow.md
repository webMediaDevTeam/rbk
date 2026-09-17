# RBQBot — Commercial Client Workflow

This document describes, end to end, how a commercial (COMERCIAL) obtains clients,
how "Mes clients" is built, and every state a client can go through while the
commercial works the call list. It also records the current state of the realtime
(WebSocket / Reverb) layer and the plan to make Rappels live.

Source of truth for the business rules: `docs/permission_and_rules.md`.

---

## 1. Roles involved

| Role | Relevant capabilities |
| --- | --- |
| `COMERCIAL` | Browse `Tous les clients`, reserve a batch, call clients, record an outcome, see `Mes clients`, see `Rappels`. |
| `ENTREPRISE` | Manages its commercials and monitors performance. |
| `ADMIN` | Enterprise + can create entreprises, view blacklist, unblock clients. |
| `SUPER_ADMIN` | Admin + can create Admins. |

---

## 2. Data model for the commercial flow

### `clients` (key columns)

| Column | Notes |
| --- | --- |
| `id` (uuid) | Primary key. |
| `rbq_data` (json) | Source of `name`, `entreprise_name`, etc. |
| `status` | `AVAILABLE`, `RESERVED`, `VOICEMAIL`, `BLACKLISTED`. |
| `is_blacklisted` (bool) | Hard block, visible to Admins. |
| `blocked_until` (timestamp) | Temporary block after a "Non" (3 months). Cleared when it expires. |
| `phone`, `email`, `municipality`, `neq`, `licence_number`, ... | Contact + RBQ data. |

> Note: `enterprise_id` and `assigned_comercial_id` were **removed** from `clients`.
> The enterprise link lives in `rbq_data` (`entreprise_id`), and the current holder
> is derived from the `reservations` table.

### `reservations` (who holds a client)

| Column | Notes |
| --- | --- |
| `id` (uuid) | Primary key. |
| `client_id` | FK clients. |
| `comercial_id` | FK users (the holder). |
| `status` | Default `RESERVED`. |
| `rappel_after` (int) | Voicemail recall amount chosen by the commercial. |
| `rappel_type` (string) | `MINUTE`, `HEURE`, `JOUR`, `SEMAINE`, `MOIS`. |
| `recall_at` (timestamp) | When the voicemail reminder becomes due. Separate from `expires_at`. |
| `expires_at` (timestamp) | Reservation lock deadline. |
| `created_at` | Creation time. |

**Holding rule:** if a `reservations` row exists for a client, the client is held.
A row is deleted when the client is released (Non, Release, Blacklist, or timer
expiry). `expires_at` only controls *when* an un-called reservation is auto-released
by the scheduler — it is **not** used to decide who holds a client anymore.

### `call_outcomes` (immutable history)

| Column | Notes |
| --- | --- |
| `id` (uuid) | Primary key. |
| `client_id` | FK clients. |
| `comercial_id` | FK users (author). |
| `outcome` | `OUI`, `NON`, `BOITE_VOCALE`, `BLACKLIST`. |
| `note` (text) | Optional note. |
| `recall_amount` (int) | For voicemail. |
| `recall_unit` (string) | For voicemail. |
| `created_at` | Timestamp. |

### `notes` (commercial-authored notes)

`type` = `GENERAL_NOTE`, `CALL_LOG`, `TASK`. Optional `due_date` and
`call_duration_seconds`. Notes and call outcomes are merged in the client
timeline on the frontend.

---

## 3. How a commercial gets "Mes clients"

"Mes clients" is not a column on the client — it is **derived from `reservations`**.

### Step 1 — Browse the pool

`GET /api/v1/clients` (`Tous les clients`). Returns only clients that are:

- `status = AVAILABLE`
- `is_blacklisted = false`
- have **no** `reservations` row (nobody holds them)

So a client held by any commercial never appears here.

### Step 2 — Reserve a batch

`POST /api/v1/clients/reserver`

```json
{ "count": 50, "quick": "7j" }
```

or, for a custom duration:

```json
{ "count": 50, "amount": 2, "unit": "SEMAINE" }
```

Candidate filter (in priority order):

1. `status = AVAILABLE`
2. `is_blacklisted = false`
3. not currently blocked (`blocked_until` null or in the past)
4. `whereDoesntHave('reservations')` — not held by anyone
5. the commercial has no previous `NON` / `BOITE_VOCALE` outcome on this client

For each candidate, inside a DB transaction with `lockForUpdate()`, the controller
re-checks that no reservation exists, then creates a `reservations` row and sets
`clients.status = RESERVED`. The response returns `{ requested, reserved, conflicts }`.

### Step 3 — "Mes clients"

`GET /api/v1/clients/mes`

```php
Client::whereHas('reservations', fn ($q) => $q->where('comercial_id', $user->id))
```

Any client with a reservation row owned by the authenticated commercial. The frontend
page `frontend/src/pages/comercial/MesClients/index.jsx` renders this list and links
each row to `/mes-clients/:id`.

### Step 4 — Client detail

`GET /api/v1/clients/{id}` returns the client plus:

- `my_reservation`: the current user's reservation row (present whenever the client is
  in "Mes clients"), or `null` if the client is held by someone else / is free
- `assigned_commercial`: who currently holds the client
- `call_outcomes`: full call history
- `notes_count`, `reservations_count`, `blocked_until`, etc.

The detail page (`frontend/src/pages/comercial/ClientDetail/index.jsx`) enables the
status options when `my_reservation` is present and disables them otherwise, showing
"Votre réservation est active." / "Réservé par X." / "Réservez ce client...".

---

## 4. Client state machine

```
                    reserve()
   AVAILABLE ──────────────────────► RESERVED
       ▲                                 │
       │                                 ├── OUI ............... stays RESERVED (hold +1 year)
       │                                 ├── NON ............... AVAILABLE + blocked 3 months
       │                                 │                       (auto-blacklist if all comercials said Non)
       │                                 ├── BOITE_VOCALE ...... VOICEMAIL (recall_at set, lock +1 month)
       │                                 ├── RELEASE ........... AVAILABLE (reservation deleted)
       │                                 └── BLACKLIST ......... BLACKLISTED (all reservations deleted)
       │
       ├──────────────────────────────── VOICEMAIL
       │        (recall timer)  ──► stays VOICEMAIL until recall
       │        (1 month timer) ──► AVAILABLE + permanent block for that commercial
       │
       └──────────────────────────────── BLACKLISTED
                (Admin unblacklist) ──► AVAILABLE (reservation history cleared)
```

---

## 5. Outcomes (detail)

Endpoint: `POST /api/v1/clients/{clientId}/outcome`

```json
{ "outcome": "BOITE_VOCALE", "note": "Message laissé", "recall_amount": 1, "recall_unit": "JOUR" }
```

### Validation gates (server-side)

| Outcome | Requirement |
| --- | --- |
| `OUI` | must hold a reservation (else `422`) |
| `BOITE_VOCALE` | must hold a reservation (else `422`) |
| `BLACKLIST` | must hold a reservation **and** have a prior call (else `422`) |
| `NON` | must hold a reservation **or** have a prior call (else `403`) |

`note` is optional for all outcomes.

### OUI — `handleOui`

- `clients.status = RESERVED`
- reservation kept, `expires_at = now + 1 year` (held until manual release)

### NON — `handleNon`

- `clients.status = AVAILABLE`
- `clients.blocked_until = now + 3 months`
- reservation deleted
- `checkAutoBlacklist()` runs (see below)

### BOITE_VOCALE — `handleBoiteVocale`

- `clients.status = VOICEMAIL`
- reservation kept:
  - `rappel_after` / `rappel_type` = chosen amount/unit
  - `recall_at = now + chosen` (drives the Rappels reminder)
  - `expires_at = now + 1 month` (the 1-month voicemail timeout)
- The commercial keeps the client in "Mes clients" for the whole month.

> Important: `recall_at` and `expires_at` are **separate**. A 1-minute recall fires a
> reminder 1 minute later but does **not** release the client — it stays held for 1 month.

### BLACKLIST — `handleBlacklist`

- `clients.status = BLACKLISTED`, `is_blacklisted = true`, `blocked_until = null`
- all reservations deleted

### RELEASE — `POST /api/v1/clients/{clientId}/release`

- creates a `CallOutcome` `OUI` with note "Client rendu disponible."
- deletes the reservation
- `clients.status = AVAILABLE`

### Auto-blacklist — `checkAutoBlacklist`

Counts distinct `comercial_id` with a `NON` outcome on the client. If that reaches the
number of active `COMERCIAL` users, the client is blacklisted.

---

## 6. Timers (scheduled command)

`backend/app/Console/Commands/ProcessTimeouts.php`, scheduled **every 10 minutes**
(`bootstrap/app.php` → `withSchedule`), signature `clients:process-timeouts`.

| Handler | Condition | Effect |
| --- | --- | --- |
| `processVoicemailTimeouts` | `recall_at != null` and `expires_at <= now` | delete reservation, `status = AVAILABLE`, create `NON` outcome "Rappel boîte vocale expiré après 1 mois...", run auto-blacklist |
| `processExpiredReservations` | `recall_at == null` and `expires_at <= now` | delete reservation; if client was `RESERVED`, set `AVAILABLE` (never called in time) |
| `processBlockedExpirations` | `status = AVAILABLE` and `blocked_until <= now` | clear `blocked_until` |

---

## 7. Notes

- `GET /api/v1/clients/{clientId}/notes` — list notes for a client.
- `POST /api/v1/notes` — `{ client_id, type, content, due_date?, call_duration_seconds? }`.
- `DELETE /api/v1/notes/{id}`.

Notes and call outcomes are merged chronologically in `NoteTimeline.jsx`. Any status
change can carry an optional note, recorded on the `call_outcomes` row.

---

## 8. Reminders (Rappels)

- Source of truth: `reservations.recall_at` where the client is in `VOICEMAIL`.
- `GET /api/v1/reminders` — all voicemail reservations, each with `is_due = recall_at <= now`.
- `GET /api/v1/reminders/count` — number of **due** reminders (badge).
- Frontend: `frontend/src/pages/comercial/Reminders/index.jsx`, badge in `Sidebar.jsx`.

---

## 9. API reference (COMERCIAL, `auth:sanctum` + role `COMERCIAL`)

| Method | Path | Action |
| --- | --- | --- |
| GET | `/api/v1/clients` | available pool (no holder) |
| GET | `/api/v1/clients/mes` | held by current user |
| POST | `/api/v1/clients/reserver` | reserve a batch |
| GET | `/api/v1/clients/{id}` | detail (+ `my_reservation`, `call_outcomes`) |
| GET | `/api/v1/clients/{clientId}/notes` | notes list |
| POST | `/api/v1/notes` | create note |
| DELETE | `/api/v1/notes/{id}` | delete note |
| POST | `/api/v1/clients/{clientId}/outcome` | OUI / NON / BOITE_VOCALE / BLACKLIST |
| POST | `/api/v1/clients/{clientId}/release` | give the client back |
| GET | `/api/v1/reminders` | voicemail reminders |
| GET | `/api/v1/reminders/count` | due reminder badge |

Routes: `backend/routes/api/commercial.php` (literal paths declared before `{id}`).

---

## 10. Frontend map

| Path | Page |
| --- | --- |
| `/clients` | `pages/comercial/ClientList/index.jsx` — pool + `ReservationModal` |
| `/mes-clients` | `pages/comercial/MesClients/index.jsx` |
| `/clients/:id`, `/mes-clients/:id` | `pages/comercial/ClientDetail/index.jsx` |
| `/reminders` | `pages/comercial/Reminders/index.jsx` |

Client detail tabs: **Détails** (`ClientDetailsTab`) and **Historique**
(`NoteTimeline` + `ActionModal`). `ActionModal` is the single entry point for every
status change and note type; its status group is always visible but each option is
disabled with an explanatory suffix when not applicable.

---

## 11. Realtime / WebSocket status

**Current state: not wired.** The `reverb` container runs on port `8080` and
`BROADCAST_CONNECTION=reverb` is set, but:

- no broadcast events exist in `backend/app`
- no `config/broadcasting.php`, `config/reverb.php`, or `routes/channels.php`
- no `laravel-echo` / `pusher-js` in the frontend

Today the UI updates by polling: the sidebar badge polls every 60s
(`useRemindersCount`, `refetchInterval: 60000`) and the Rappels page fetches on mount.
A reminder created elsewhere does not appear live.

### Future work — make Rappels live via Reverb

Add these environment values to the `backend`, `reverb`, `queue` (and the frontend)
services, replacing the current inline env in `docker-compose.yml`:

```json
{
  "REVERB_APP_ID": "rbqbot",
  "REVERB_APP_KEY": "rbqbot-key",
  "REVERB_APP_SECRET": "rbqbot-secret",
  "REVERB_HOST": "0.0.0.0",
  "REVERB_PORT": "8080",
  "REVERB_SCHEME": "http",
  "REVERB_SERVER_HOST": "0.0.0.0",
  "REVERB_SERVER_PORT": "8080",
  "BROADCAST_CONNECTION": "reverb",
  "VITE_REVERB_APP_KEY": "rbqbot-key",
  "VITE_REVERB_HOST": "localhost",
  "VITE_REVERB_PORT": "8080",
  "VITE_REVERB_SCHEME": "http"
}
```

Implementation checklist:

1. `php artisan install:broadcasting` (publishes `config/broadcasting.php`,
   `config/reverb.php`, `routes/channels.php`).
2. Backend event `App\Events\ReminderDue implements ShouldBroadcast` on channel
   `private-App.Models.User.{id}` as `reminder.due`, fired from
   `OutcomeController::handleBoiteVocale` and from `ProcessTimeouts`.
3. Authorize the private channel in `routes/channels.php`.
4. Frontend: add `laravel-echo` + `pusher-js`, create
   `frontend/src/lib/echo.js` with `window.Pusher` + `new Echo({ broadcaster: 'reverb', ... })`.
5. Subscribe in `useOutcomes.js`: on `reminder.due`, invalidate `['reminders']` and
   `['reminders-count']`. Drop the 60s `refetchInterval` once live.
