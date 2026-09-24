<?php

use App\Http\Middleware\CheckRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function ($schedule) {
        // Rappels expirés (BV / Injoignable) : échec automatique, réservation conservée
        $schedule->command('clients:process-timeouts')->everyTenMinutes();

        // Réactivation des UNAVAILABLE_TEMP (returned_at atteint) -> AVAILABLE pour tous
        $schedule->command('clients:reactivate')->hourly();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => CheckRole::class,
            'log.http' => App\Http\Middleware\LogHttpTraffic::class,
        ]);

        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
