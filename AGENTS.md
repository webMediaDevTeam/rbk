# Règles du dépôt RBK

> À lire avant toute modification. `docs/RULES.md` reste la référence des
> règles **métier** ; ce fichier porte les règles de **travail** sur le code.

## 1. La base de dev MySQL ne se vide JAMAIS

**Interdit** — ces commandes suppriment les données de `rbqbot` (MySQL) :

```bash
php artisan migrate:fresh      # DROP de toutes les tables
php artisan migrate:refresh
php artisan db:wipe
```

**Autorisé** :

| Commande | Effet |
|---|---|
| `php artisan migrate` | applique les migrations en attente (aucune suppression) |
| `php artisan migrate:status` | lecture seule |
| `php artisan db:seed` | **ajoute** les données de dev (restauration possible) |
| `docker exec rbqbot-mysql mysql ... -e "SELECT ..."` | lecture seule |

## 2. Les tests tournent sur SQLite in-memory, jamais sur MySQL

*Ne pas tester / lancer la suite est toujours autorisé et sans risque ; si on
la lance, elle ne doit toucher aucune base réelle.*

* Les tests utilisent `RefreshDatabase`, qui exécute un `migrate:fresh`
  **sur la base configurée** à chaque classe de test.
* `backend/phpunit.xml` déclare `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:`,
  mais **ce n'est pas suffisant** : docker-compose injecte
  `DB_CONNECTION=mysql` / `DB_DATABASE=rbqbot` dans l'environnement du
  conteneur, donc dans `$_SERVER`, que Laravel lit **avant** `$_ENV` et
  `putenv()` (référentiel `Illuminate\Support\Env`). Même avec
  `force="true"` sur les `<env>`, les tests partaient donc contre MySQL.
* Le garant est `backend/tests/bootstrap.php` (déclaré dans `phpunit.xml`),
  qui force `$_SERVER['DB_CONNECTION']=sqlite` + `DB_DATABASE=:memory:`
  **avant** le boot de l'application.
* ⚠️ Ne pas supprimer ce bootstrap, et ne pas activer `php artisan config:cache`
  dans l'environnement de test (le cache de config figerait les variables
  d'environnement et neutraliserait l'écrasement).

Vérification qu'aucune donnée réelle n'est visée : les tests ne doivent jamais
ouvrir une connexion `mysql`.

## 3. Incident de référence (2026-09-25)

`php artisan test` lancé dans le conteneur a vidé `rbqbot` (150 clients,
3 entreprises, 7 utilisateurs, 20 catégories) : `RefreshDatabase` +
`migrate:fresh` exécutés contre MySQL. Restauration faite avec
`php artisan db:seed` (→ `QuebecConstructionSeeder`).

**Si ça se reproduit** : `docker exec rbqbot-backend-dev php artisan db:seed`
(no `--fresh`, no `migrate:fresh`) pour régénérer les données de dev.

## 4. Fichiers clés

* `docs/RULES.md` — règles métier (rôles, permissions, workflow d'appel) ; fait foi.
* `backend/tests/bootstrap.php` — écrasement d'environnement des tests (§2).
* `backend/phpunit.xml` — déclaration du bootstrap + `<env>` de secours.
* `docker-compose.yml` — variables d'environnement des services (source des
  valeurs `$_SERVER` évoquées au §2).
