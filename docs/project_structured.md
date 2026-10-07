```markdown
# Containers Architecture Setup Todo List

## Phase 1: Environment & Project Structure
- [x] Create folder structure on host machine:
  - `./frontend`
  - `./backend`
  - `./nginx/conf.d`
- [x] Prepare root `.env` file with essential environment variables (`MYSQL_ROOT_PASSWORD`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_APP_ID`).
- [x] Create dedicated Docker network: `docker network create rbqbot-network`.

---

## Phase 2: Separate Service Dockerfiles

### 1. Frontend (`./frontend/Dockerfile`)
- [x] Create a multi-stage Dockerfile:
  - **Stage 1 (Builder):** Use `node:18-alpine` → **`node:22-alpine`** (Vite 8 / plugin-react 6 exigent Node `^20.19.0 || >=22.12.0` : `node:18-alpine` ferait échouer `npm ci`), copy source code, install dependencies (`npm ci`), and build production bundle (`npm run build`).
  - **Stage 2 (Production Server):** Use `nginx:alpine`, copy built assets from builder stage (`/app/dist`) to `/usr/share/nginx/html`.
  - *Stage bonus `development`* : conserve le serveur de dev (`npm run dev`) pour la stack de dev via `build.target`.
- [x] Configure client-side routing fallback (`try_files $uri $uri/ /index.html;`) in custom frontend Nginx config. → `./frontend/nginx.conf`

### 2. Backend API (`./backend/Dockerfile`)
- [x] Use `php:8.2-fpm-alpine` as base image.
- [x] Install required PHP extensions (`pdo_mysql`, `bcmath`, `gd`, `zip`, `pcntl`, `redis`).
- [x] Copy Composer binary (`php:8.2` or official Composer image) and install production dependencies (`composer install --no-dev --optimize-autoloader`).
- [x] Set appropriate storage and cache permissions (`chmod -R 775 storage bootstrap/cache`).
- [x] Expose standard FastCGI port `9000`.

*Validations : `docker build` OK pour les deux images, `php -m` contient bien
`pdo_mysql bcmath gd zip pcntl redis`, `nginx -t` OK, fallback SPA testé
(`GET /clients/42` → 200 `text/html`). `.dockerignore` ajoutés : `vendor/`,
`.env`, `node_modules`, `dist` exclus du contexte (aucune secret dans l'image).*

---

## Phase 3: Docker Compose Configuration (`docker-compose.yml`)

Configure each service in its own isolated container on `rbqbot-network`:

- [x] **Database Container (`mysql`)**
  - Image: `mysql:8.0`
  - Container name: `rbqbot-mysql`
  - Persist data via named volume `mysql_data` mounted to `/var/lib/mysql`.
  - Add MySQL healthcheck.

- [x] **Database Management Container (`phpmyadmin`)**
  - Image: `phpmyadmin/phpmyadmin`
  - Container name: `rbqbot-phpmyadmin`
  - Set `PMA_HOST: mysql`.
  - Map external port (e.g., `8081:80`).

- [x] **In-Memory Cache & Queue Broker (`redis`)**
  - Image: `redis:alpine`
  - Container name: `rbqbot-redis`
  - Persist cache via named volume `redis_data` mounted to `/data`.
  - Add Redis healthcheck.

- [x] **Backend PHP-FPM Service (`backend`)**
  - Build context: `./backend`
  - Container name: `rbqbot-backend`
  - Mount persistent storage volume for `storage/app/public` and uploaded files.
  - Depend on `mysql` and `redis` healthchecks.

- [ ] **WebSocket Server Container (`reverb`) — RETIRÉ le 2026-09-30**
  - `laravel/reverb` n'est pas installé (absent de `composer.json` et de `vendor/`) :
    `php artisan reverb:start` échouait en permanence, le conteneur tournait en
    crash-loop (défaut hérité de l'ancien compose).
  - Aucun usage du broadcast dans le code (ni `ShouldBroadcast`, ni client Echo) :
    service supprimé du compose, `BROADCAST_CONNECTION=log`, gateway `/app` → `503`.
  - Réactivation : `composer require laravel/reverb`, remettre le service, puis
    décommenter le proxy `/app` (procédure en commentaire dans
    `nginx/conf.d/default.conf`).

- [x] **Queue Worker Container (`queue`)**
  - Build context: `./backend`
  - Container name: `rbqbot-queue`
  - Override command: `php artisan queue:work --queue=default --tries=3 --timeout=90`

- [x] **Task Scheduler Container (`scheduler`)**
  - Build context: `./backend`
  - Container name: `rbqbot-scheduler`
  - Override command: `php artisan schedule:work`

- [x] **Frontend Application (`frontend`)**
  - Build context: `./frontend`
  - Container name: `rbqbot-frontend`
  - Serve static assets via lightweight internal Nginx.

- [x] **Reverse Proxy Edge Server (`nginx`)**
  - Image: `nginx:alpine`
  - Container name: `rbqbot-gateway`
  - Map external ports `80:80` (and `443:443` for SSL).
  - Mount routing config `./nginx/conf.d/default.conf`.

*Validations : `docker compose -f docker-compose.prod.yml config` OK (9 services,
ancres YAML fusionnées, `MYSQL_*` → `DB_*` cohérents, `REVERB_APP_*` injectés).
Écarts assumés : code backend fourni par l'image (pas de bind mount), volume
`backend_uploads` pré-rempli automatiquement avec `avatars/` + `logos/`, et
montages `./backend/public` + `backend_uploads` sur le gateway pour servir
`/storage/*` (routing en phase 4). ⚠️ phpMyAdmin publié sur `0.0.0.0:8081`.*

---

## Phase 4: Main Reverse Proxy Router (`./nginx/conf.d/default.conf`)

- [x] Route `/` requests to `http://rbqbot-frontend:80`.
- [x] Route `/api` and PHP execution requests to `rbqbot-backend:9000` via FastCGI (`fastcgi_pass`).
- [x] Route `/app` / WebSockets → **désactivée depuis le retrait de `reverb`** :
  `return 503 "WebSockets non configurés"` (au lieu de router vers un conteneur
  absent). Bloc proxy conservé en commentaire, avec les en-têtes HTTP/1.1
  requis :
  ```nginx
  proxy_set_header Upgrade $http_upgrade;
  proxy_set_header Connection "Upgrade";

```

*Validations (`nginx -t` + test de bout en bout sur réseau temporaire, stack dev
non touchée, conteneurs/nettoyage supprimés ensuite) :*

| Test | Résultat |
|---|---|
| `GET /` (SPA) | `200 text/html` |
| `GET /build/index-*.js` | `200 application/javascript` |
| `GET /clients/42` (fallback React) | `200 text/html` |
| `GET /api/v1/...` (`Accept: application/json`) | `401 application/json` ← **FastCGI → Laravel OK** |
| `GET /storage/avatars/*.jpg` | `200 image/jpeg` ← **uploads OK** |
| `GET /app/uptime` (Reverb) | `200` — *valide avant le retrait de `reverb` (30/09) ; depuis : `503`* |
| `Upgrade: websocket` → Reverb | `X-Seen-Upgrade: websocket`, `X-Seen-Connection: upgrade` — *hors service depuis le 30/09* |
| `GET /sanctum/csrf-cookie` (`Origin: …`) | `204` + `Access-Control-Allow-Origin` ← **route ajoutée le 30/09** (le front l'appelle hors `/api`) |
| `GET /foo.php` | `404` (exécution PHP bloquée) |
| `GET /.env` | `403` (fichiers cachés niés) |

*Détails : `Connection "Upgrade"` littéral remplacé par un `map
$http_upgrade $connection_upgrade` (équivalent fonctionnel pour les WS, mais ne
casse pas les requêtes HTTP simples vers `/app`) ; résolution DNS différée via
`127.0.0.11` (nginx démarre même si un service est absent et suit les
changements d'IP) ; `SCRIPT_FILENAME` fixé à
`/var/www/html/public/index.php` (résolu par PHP-FPM dans son propre
conteneur) ; locations en `^~` pour que `/api` et `/app` échappent à la regex
bloquant les `.php`. Le port 443 est publié mais sans certificat : aucun
`server` SSL n'est déclaré (à ajouter en HTTPS plus tard).*

---

## Phase 5: Deployment & Validation

* [x] Build all images: `docker compose build --no-cache` — **exécuté en local** (`backend` + `frontend` ; `reverb`/`queue`/`scheduler` partagent l'image de `backend`), tags `rbk-backend:prod` / `rbk-frontend:prod` (distincts des tags `:latest` de la stack de dev, restés intacts). Contenu vérifié : bundle Vite avec `http://51.255.192.50/api/v1` figé, `vendor/autoload.php` + extensions `pdo_mysql gd redis pcntl` présents.
* [x] Spin up containers in detached mode — **exécuté sur le VPS par le CI à
  chaque push** : `docker compose -f docker-compose.prod.yml up -d --build
  --remove-orphans` (garde-fou : échec si un secret est vide ; diagnostic
  complet `ps -a` + logs mysql/backend en cas d'échec de `up`).
* [x] Run initial Laravel setup commands — **exécuté par le CI** :
* `php artisan migrate --force` → `INFO Nothing to migrate.` (30/09, 09:42)
* `php artisan config:cache` + `php artisan view:cache` → OK
* `php artisan route:cache` → **retiré** : `routes/web.php` contient des
  closures, l'artisan échouerait et annulerait le déploiement
* `php artisan event:cache` → non exécuté (non requis)

* [x] Verify connectivity — **réel sur `http://51.255.192.50` (30/09)** :
* Frontend SPA → `200` (bundle `/build/index-*.js`, `VITE_API_URL` figé sur
  `http://51.255.192.50`, plus de `@vite/client`)
* API `/api/v1/…` → `401 application/json` + `Access-Control-Allow-Origin`
  exacte derrière le gateway (`:8000` n'est plus publié)
* `GET /sanctum/csrf-cookie` → `204` + cookie de session (route ajoutée au gateway)
* phpMyAdmin → `200` sur `http://51.255.192.50:8081` (compte `rbqbot`)
* WebSocket `ws://<your-ip>/app` → `503` (**reverb retiré**, voir phase 3)

**Statut (30/09) : les 5 phases sont livrées.** Le déploiement est intégralement
piloté par `.github/workflows/docker-deploy.yml` : à chaque push sur `master`,
le CI régénère le `.env` du VPS **depuis les secrets GitHub** (`APP_KEY`,
`DB_PASSWORD`, `DB_ROOT_PASSWORD`, `REVERB_APP_*`) — le `.env` local n'est
plus versionné (`git rm --cached`, voir `.gitignore`) et n'est jamais utilisé
en production. Correctifs CI apportés ce jour : bloc `env:` (les `envs:` ne
transmettaient que des noms de variables → `APP_URL=http://` → `Invalid URI.`),
identifiants MySQL de l'application au lieu de `root` (root n'est joignable
qu'en socket), `--remove-orphans`, et `restart nginx` (la config du gateway
est un bind mount : compose ne la détecte pas comme un changement). Aucun
`migrate` destructeur : `Nothing to migrate.` Aucun usage des WebSockets
(ni `ShouldBroadcast`, ni Echo) — voir le retrait de `reverb` en phase 3.



```

```