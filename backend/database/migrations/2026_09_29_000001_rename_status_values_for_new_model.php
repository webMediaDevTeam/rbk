<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Alignement des valeurs de statut sur le modèle (docs/models.puml).
 *
 *  clients.status      SUCCESS        -> CONFIRMED
 *                      UNAVAILABLE_TEMP -> UNAVAILABLE
 *                      (AVAILABLE / RESERVED / BLACKLISTED inchangés)
 *
 *  reservations.status EN_ATTENT      -> PENDING
 *                      OUI            -> YES
 *                      NON            -> NO
 *                      BV             -> BV_VOICEMAIL
 *                      INJOINABLE     -> CALL_BACK
 *                      (REALIZED : valeur réservée du modèle, non émise par le workflow)
 *
 * Aucune donnée perdue : uniquement des UPDATE, entièrement réversible (down()).
 * NB : IN_PROGRESS reste un statut DÉRIVÉ d'affichage (Client::displayStatus()),
 * il n'est jamais stocké — voir docs/RULES.md §2.
 */
return new class extends Migration
{
    private const CLIENT_STATUS_MAP = [
        'SUCCESS' => 'CONFIRMED',
        'UNAVAILABLE_TEMP' => 'UNAVAILABLE',
    ];

    private const RESERVATION_STATUS_MAP = [
        'EN_ATTENT' => 'PENDING',
        'OUI' => 'YES',
        'NON' => 'NO',
        'BV' => 'BV_VOICEMAIL',
        'INJOINABLE' => 'CALL_BACK',
    ];

    public function up(): void
    {
        $this->mapValues('clients', 'status', self::CLIENT_STATUS_MAP);
        $this->mapValues('reservations', 'status', self::RESERVATION_STATUS_MAP);
    }

    public function down(): void
    {
        $this->mapValues('clients', 'status', array_flip(self::CLIENT_STATUS_MAP));
        $this->mapValues('reservations', 'status', array_flip(self::RESERVATION_STATUS_MAP));
    }

    /**
     * @param array<string, string> $map valeur d'origine => valeur cible
     */
    private function mapValues(string $table, string $column, array $map): void
    {
        foreach ($map as $from => $to) {
            DB::table($table)->where($column, $from)->update([$column => $to]);
        }
    }
};
