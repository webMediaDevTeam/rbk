<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Champs dérivés `phone_normalized` / `simple_name`
 * (migration `add_search_normalization_to_clients_table` + observateur
 * `App\Observers\ClientObserver`).
 */
class ClientNormalizationObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_client_populates_both_normalized_fields(): void
    {
        $client = Client::create([
            'name' => '  José   Tremblay ',
            'status' => Client::STATUS_AVAILABLE,
            'phone' => '+1 (819) 418-6550 ext. 5417',
        ]);

        // Clé de chiffres nationaux : extension, `+1` et ponctuation retirés.
        $this->assertSame('8194186550', $client->phone_normalized);
        // Sans accent, sans casse, espaces réduits.
        $this->assertSame('jose tremblay', $client->simple_name);

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'phone_normalized' => '8194186550',
            'simple_name' => 'jose tremblay',
        ]);
    }

    public function test_updating_the_phone_recomputes_only_phone_normalized(): void
    {
        $client = Client::create([
            'name' => 'ACME Construction',
            'status' => Client::STATUS_AVAILABLE,
            'phone' => '514-555-0100',
        ]);

        $client->update(['phone' => '819.418.6550']);

        $this->assertSame('8194186550', $client->fresh()->phone_normalized);
        $this->assertSame('acme construction', $client->fresh()->simple_name);
    }

    public function test_updating_the_name_recomputes_only_simple_name(): void
    {
        $client = Client::create([
            'name' => 'ACME Construction',
            'status' => Client::STATUS_AVAILABLE,
            'phone' => '514-555-0100',
        ]);

        $client->update(['name' => 'BOEUF CIVIL LTD.']);

        $this->assertSame('boeuf civil ltd.', $client->fresh()->simple_name);
        $this->assertSame('5145550100', $client->fresh()->phone_normalized);
    }

    public function test_empty_phone_and_name_yield_null_columns(): void
    {
        $client = Client::create([
            'name' => 'Sans nom',
            'status' => Client::STATUS_AVAILABLE,
            'phone' => null,
        ]);

        $this->assertNull($client->phone_normalized);

        $client->update(['name' => '   ', 'phone' => 'appareil sans numéro']);

        $this->assertNull($client->fresh()->simple_name);
        // Un champ « téléphone » sans chiffre ne produit aucune clé.
        $this->assertNull($client->fresh()->phone_normalized);
    }

    public function test_normalized_columns_are_not_mass_assignable(): void
    {
        $client = Client::create([
            'name' => 'ACME Construction',
            'status' => Client::STATUS_AVAILABLE,
            'phone' => '514-555-0100',
        ]);

        $client->fill([
            'phone_normalized' => '0000000000',
            'simple_name' => 'n importe quoi',
        ])->save();

        $fresh = $client->fresh();

        $this->assertSame('5145550100', $fresh->phone_normalized);
        $this->assertSame('acme construction', $fresh->simple_name);
    }

    public function test_simple_name_folds_accents_case_and_ligatures(): void
    {
        $this->assertSame('jose tremblay', Client::simpleName('JOSÉ  TREMBLAY'));
        $this->assertSame('oeil de boeuf', Client::simpleName('Œil de Bœuf'));
        $this->assertSame('quebec', Client::simpleName('Québec'));
        $this->assertNull(Client::simpleName(null));
        $this->assertNull(Client::simpleName('  '));
    }
}
