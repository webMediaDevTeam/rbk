<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Expiration des dérogations « Connexion hors zones » (`users.gps_bypass`).
 *
 * Un gestionnaire qui active le switch accorde **24 h** (`User::setGpsBypass()`
 * pose `gps_bypass_expires_at = now + 24 h`). Le refus géo est déjà immédiat
 * côté serveur — `GeofenceService` ne teste que `User::isGpsBypassActive()` —
 * ce cron ne fait que **ranger la base et l'affichage** : le drapeau repasse à
 * `false` et l'échéance à `null`, pour que la colonne « Hors zones » de la page
 * Employés montre un switch éteint plutôt qu'un `true` périmé.
 *
 * Les dérogations sans échéance (`gps_bypass_expires_at IS NULL`, accordées
 * avant la limitation dans le temps) ne sont **pas** touchées.
 */
class ExpireGpsBypass extends Command
{
    protected $signature = 'gps:expire-bypass';

    protected $description = 'Disable expired GPS bypass derogations (users.gps_bypass older than their 24h expiry)';

    public function handle(): int
    {
        // `gps_bypass = true` + échéance atteinte (scope `expiredGpsBypass`).
        // Update groupé : pas d'événement modèle à déclencher, la dérogation
        // n'est portée par aucune autre table.
        $count = User::expiredGpsBypass()->update([
            'gps_bypass' => false,
            'gps_bypass_expires_at' => null,
        ]);

        $this->info($count.' dérogation(s) GPS expirée(s) -> hors zones refusé.');

        return self::SUCCESS;
    }
}
