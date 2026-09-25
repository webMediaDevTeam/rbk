<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Remplace l'intégralité des clients par les enregistrements d'un export RBQ
 * (JSON, clés d'affichage françaises).
 *
 *   php artisan db:seed --class=ClientsFromJsonSeeder
 *   CLIENTS_JSON=/chemin/vers/export.json php artisan db:seed --class=ClientsFromJsonSeeder
 *
 * Fichier lu par défaut : `database/data/clients.json`.
 *
 * ⚠️ Destructif : les clients existants sont supprimés, donc aussi leurs lignes
 * enfants (`reservations`, `call_outcomes`, `notes`, en cascade) — les listes
 * repartent vierges. `DatabaseSeeder` ne l'appelle pas : il faut le lancer à
 * la main.
 */
class ClientsFromJsonSeeder extends Seeder
{
    public function run(): void
    {
        $path = env('CLIENTS_JSON', database_path('data/clients.json'));

        // Tout contrôle avant la suppression : un fichier absent/illisible ne
        // doit jamais se traduire par une table vidée sans import.
        $rows = $this->rows($path);

        if ($rows === null) {
            return;
        }

        DB::transaction(function () use ($rows) {
            Client::query()->delete();

            foreach ($rows as $row) {
                Client::create($this->attributes($row));
            }
        });

        // `query()->delete()` ne déclenche pas les événements du modèle :
        // on invalide à la main les listes distinctes mises en cache.
        Client::forgetDistinctValues();

        $this->command?->info(sprintf('%d clients importés depuis %s', count($rows), $path));
    }

    /** @return list<array>|null */
    private function rows(string $path): ?array
    {
        if (! is_file($path)) {
            $this->command?->error("Fichier introuvable : {$path}");

            return null;
        }

        $payload = json_decode((string) file_get_contents($path), true);

        if (! is_array($payload)) {
            $this->command?->error("JSON illisible : {$path}");

            return null;
        }

        // Format simple : liste de clients. Encapsulé : {"clients": [...]}.
        $rows = is_array($payload['clients'] ?? null) ? $payload['clients'] : $payload;

        if (! array_is_list($rows) || $rows === []) {
            $this->command?->error("Aucun client à importer (liste vide ou format inattendu) : {$path}");

            return null;
        }

        return $rows;
    }

    /**
     * Clés du payload (FR) → attributs `clients`, plus les colonnes que le
     * payload ne porte pas (statut, drapeaux).
     *
     * @param  array  $row
     * @return array<string, mixed>
     */
    private function attributes(array $row): array
    {
        // L'export livre le N° de licence sous une clé vide (« ») : on la
        // renomme avant le mapping, `Licence` est la clé attendue.
        if (array_key_exists('', $row)) {
            $row['Licence'] ??= $row[''];
            unset($row['']);
        }

        $attributes = array_merge(Client::attributesFromPayload($row), [
            'status' => 'AVAILABLE',
            'is_blacklisted' => false,
            'returned_at' => null,
        ]);

        // Catégories du filtre = mêmes libellés que les catégories autorisées
        // (docs/RULES.md §12) : `GET /filters` les lit dans `clients.categories`.
        $attributes['categories'] = $attributes['authorized_categories'] ?? [];

        // « Licence propre : Oui / Non » : un numéro de licence propre
        // renseigné répond oui (convention du seeder d'AppData).
        $attributes['licence_propre'] = $attributes['licence_propre_numero'] !== null;

        return $attributes;
    }
}
