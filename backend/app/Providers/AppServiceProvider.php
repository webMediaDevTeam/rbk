<?php

namespace App\Providers;

use App\Models\Client;
use App\Observers\ClientObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Colonnes dérivées `phone_normalized` / `simple_name` : tenues à
        // jour à chaque écriture du modèle (cf. App\Observers\ClientObserver).
        Client::observe(ClientObserver::class);
    }
}
