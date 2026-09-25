<?php

/**
 * Bootstrap PHPUnit — écrasement volontaire des variables d'environnement.
 *
 * ⚠️ Règle du projet : `php artisan test` ne doit JAMAIS toucher MySQL.
 *
 * docker-compose définit `DB_CONNECTION=mysql` / `DB_DATABASE=rbqbot` dans
 * l'environnement du conteneur. Ces valeurs arrivent via `$_SERVER`, que
 * PHPUnit ne surcharge pas — même avec `force="true"` dans phpunit.xml, il
 * n'écrit que `$_ENV` et `putenv()`, et le référentiel de Laravel
 * (Illuminate\Support\Env) lit `$_SERVER` EN PREMIER.
 *
 * Sans cet écrasement, les tests tournent contre la vraie base `rbqbot` et
 * `RefreshDatabase` y exécute un `migrate:fresh` = TOUTES les données de dev
 * supprimées à chaque lancement de la suite.
 *
 * Ce fichier s'exécute avant le boot de l'application : il force donc les
 * valeurs, quel que soit l'hôte (conteneur, CI, poste local).
 */
$forcedEnvironment = [
    'APP_ENV'       => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE'   => ':memory:',
    'DB_URL'        => '',
];

foreach ($forcedEnvironment as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

require __DIR__.'/../vendor/autoload.php';
