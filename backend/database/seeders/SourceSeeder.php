<?php

namespace Database\Seeders;

use App\Models\Source;
use Illuminate\Database\Seeder;

/**
 * Sources initiales du répertoire `sources` (liste **fermée**, lue par
 * `GET /api/v1/sources`, aucun CRUD).
 *
 * Idempotent (`firstOrCreate`) : rejouable sans doublon —
 * `php artisan db:seed --class=SourceSeeder`.
 */
class SourceSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Affaire', 'Angalis'] as $name) {
            Source::firstOrCreate(['name' => $name]);
        }
    }
}
