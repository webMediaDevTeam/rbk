<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nettoyage applicatif du webhook `POST /api/v1/clients/bulk-upsert`
 * (docs/RULES.md §12) : le webhook ne valide pas, il normalise.
 *
 *   téléphone formaté · courriel validé · libellés décodifiés et dé-cassés
 *   · code `[1.23]` retiré · doublons de liste retirés · listes en JSON
 *
 * Le script d'import envoie **tout en chaîne** : ces tests prouvent que l'API
 * seule se charge des types et du nettoyage.
 */
class PublicClientDataCleanupTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/clients/bulk-upsert';

    private function import(array $client): Client
    {
        $this->postJson(self::URI, ['clients' => [$client]])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.failed', 0);

        return Client::where('licence_number', $client['Licence'])->firstOrFail();
    }

    // ------------------------------------------------------------- Téléphone

    public function test_phone_is_formatted_and_the_extension_is_kept(): void
    {
        $client = $this->import([
            'Licence' => 'L-9001',
            'Téléphone' => '5143535820 Ext.: 5417',
        ]);

        $this->assertSame('514-353-5820 ext. 5417', $client->phone);
    }

    public function test_phone_already_formatted_is_left_alone(): void
    {
        $this->assertSame(
            '418-555-0001',
            $this->import(['Licence' => 'L-9002', 'Téléphone' => '418-555-0001'])->phone
        );
    }

    public function test_too_short_phone_is_not_mangled(): void
    {
        $this->assertSame('555', $this->import(['Licence' => 'L-9003', 'Téléphone' => '555'])->phone);
        $this->assertNull($this->import(['Licence' => 'L-9004', 'Téléphone' => '  '])->phone);
    }

    // -------------------------------------------------------------- Courriel

    public function test_email_is_lowercased_and_validated(): void
    {
        $valid = $this->import(['Licence' => 'L-9010', 'Courriel' => ' Bob@Example.COM ']);
        $this->assertSame('bob@example.com', $valid->email);

        $invalid = $this->import(['Licence' => 'L-9011', 'Courriel' => 'pas-un-courriel']);
        $this->assertNull($invalid->email, 'Un courriel invalide est écarté plutôt qu\'écrit tel quel.');

        $multiple = $this->import(['Licence' => 'L-9012', 'Courriel' => 'junk, contact@acme.ca']);
        $this->assertSame('contact@acme.ca', $multiple->email);
    }

    // --------------------------------------------------------------- Libellés

    public function test_labels_are_trimmed_uppercased_labels_are_rewritten(): void
    {
        $client = $this->import([
            'Licence' => 'L-9020',
            'Municipalité' => "  Montréal\u{00A0} ",
            'Région administrative' => 'MONTÉRÉGIE',
        ]);

        $this->assertSame('Montréal', $client->municipality);
        $this->assertSame('Montérégie', $client->administrative_region);
    }

    public function test_label_code_prefix_is_removed(): void
    {
        $this->assertSame(
            'Gestion de projets et de chantiers',
            $this->import([
                'Licence' => 'L-9021',
                'Catégories et sous-catégories autorisées' => ['[1.23] Gestion de projets et de chantiers'],
            ])->authorized_categories[0]
        );
    }

    // --------------------------------------------------- Catégories / listes

    public function test_category_codes_casing_and_duplicates_are_cleaned(): void
    {
        $client = $this->import([
            'Licence' => 'L-9030',
            'Catégories et sous-catégories autorisées' => [
                '[1.23] Gestion de projets',
                '1.23 — Gestion de projets',     // doublon après retrait du code
                'ADM — Électricité',
                'ÉLECTRICITÉ',                   // doublon insensible à la casse
                '  Électricité  ',               // doublon avec espaces
                '   ',                           // vide : retiré
            ],
        ]);

        $this->assertSame(['Gestion de projets', 'Électricité'], $client->authorized_categories);
        // `categories` (colonne des filtres) reprend les mêmes libellés.
        $this->assertSame(['Gestion de projets', 'Électricité'], $client->categories);
    }

    public function test_respondants_are_deduplicated_without_losing_the_qualification(): void
    {
        $client = $this->import([
            'Licence' => 'L-9031',
            'Répondants / Interlocuteurs (Qualifications)' => [
                'Jean Dupont',
                ' jean dupont ',
                'Jean Dupont (Sécurité, Construction)',
            ],
        ]);

        $this->assertSame(
            ['Jean Dupont', 'Jean Dupont (Sécurité, Construction)'],
            $client->respondents
        );
    }

    // ----------------------------------------------------------- Cautionnement

    public function test_surety_json_string_is_exposed_as_json_and_as_string(): void
    {
        $client = $this->import([
            'Licence' => 'L-9040',
            'Cautionnement (Compagnie / Association)' => '["Assurance ABC","Courtier XYZ","Assurance ABC"]',
        ]);

        $this->assertSame(['Assurance ABC', 'Courtier XYZ'], $client->cautionnement_compagnie);
        $this->assertSame('Assurance ABC', $client->surety_company);
    }

    public function test_plain_surety_string_becomes_a_one_element_json_list(): void
    {
        $client = $this->import([
            'Licence' => 'L-9041',
            'Cautionnement (Compagnie / Association)' => 'Assurance ABC',
        ]);

        $this->assertSame(['Assurance ABC'], $client->cautionnement_compagnie);
        $this->assertSame('Assurance ABC', $client->surety_company);
    }

    // --------------------------------------------- Tout arrive en chaîne

    public function test_everything_sent_as_string_is_typed_by_the_api_only(): void
    {
        $client = $this->import([
            'Licence' => 'L-9050',
            'Licence (propre)' => '40014001',
            'Nombre de répondants' => '3',
            'Nombre de sous-catégories' => '2',
            'Montant de la caution ($)' => '20000',
            'Date de début / délivrance' => '2025-05-28T00:00:00',
            'Date de fin / paiement annuel' => '2027-05-28T00:00:00',
            'Répondants / Interlocuteurs (Qualifications)' => '["André Un","André Un"]',
            'Catégories et sous-catégories autorisées' => '["[1.23] Électricité","ÉLECTRICITÉ"]',
        ]);

        $this->assertSame(40014001, $client->licence_propre_numero);
        $this->assertSame(3, $client->respondent_count);
        $this->assertSame(2, $client->sub_category_count);
        $this->assertSame('20000.00', (string) $client->surety_amount);
        $this->assertSame('2025-05-28', $client->licence_start_date->toDateString());
        $this->assertSame('2027-05-28', $client->licence_end_date->toDateString());
        $this->assertSame(['André Un'], $client->respondents);
        $this->assertSame(['Électricité'], $client->authorized_categories);
    }

    public function test_licence_lookup_key_is_trimmed(): void
    {
        $this->import(['Licence' => 'L-9060', 'Téléphone' => '5145550100']);

        // Relecture avec des espaces autour : même client, pas de doublon.
        $this->postJson(self::URI, ['clients' => [['Licence' => '  L-9060  ']]])
            ->assertOk()
            ->assertJsonPath('data.unchanged', 1);

        $this->assertSame(1, Client::count());
        $this->assertSame('L-9060', Client::firstOrFail()->licence_number);
    }

    // --------------------------------------------- Cas données déjà typées / propres

    public function test_already_clean_native_types_are_accepted_without_regression(): void
    {
        $client = $this->import([
            'Licence' => 'L-9070',
            'Licence (propre)' => 50025002,
            'Nombre de répondants' => 2,
            'Nombre de sous-catégories' => 1,
            'Montant de la caution ($)' => 25000.50,
            'Date de début / délivrance' => '2026-01-15',
            'Date de fin / paiement annuel' => '2028-01-15',
            'Téléphone' => '514-555-0199',
            'Courriel' => 'propre@example.com',
            'Municipalité' => 'Montréal',
            'Région administrative' => 'Montérégie',
            'Répondants / Interlocuteurs (Qualifications)' => ['Jean Dupont', 'Marie Curie'],
            'Catégories et sous-catégories autorisées' => ['Gestion de projets', 'Électricité'],
            'Cautionnement (Compagnie / Association)' => ['Assurance Alpha', 'Courtier Beta'],
        ]);

        $this->assertSame('L-9070', $client->licence_number);
        $this->assertSame(50025002, $client->licence_propre_numero);
        $this->assertSame(2, $client->respondent_count);
        $this->assertSame(1, $client->sub_category_count);
        $this->assertSame('25000.50', (string) $client->surety_amount);
        $this->assertSame('2026-01-15', $client->licence_start_date->toDateString());
        $this->assertSame('514-555-0199', $client->phone);
        $this->assertSame('propre@example.com', $client->email);
        $this->assertSame('Montréal', $client->municipality);
        $this->assertSame('Montérégie', $client->administrative_region);
        $this->assertSame(['Jean Dupont', 'Marie Curie'], $client->respondents);
        $this->assertSame(['Gestion de projets', 'Électricité'], $client->authorized_categories);
        $this->assertSame(['Assurance Alpha', 'Courtier Beta'], $client->cautionnement_compagnie);
        $this->assertSame('Assurance Alpha', $client->surety_company);
    }

    public function test_snake_case_keys_and_pipe_separated_strings_and_currency_symbols(): void
    {
        $response = $this->postJson(self::URI, ['clients' => [[
            'licence_number' => 'L-9080',
            'licence_propre_numero' => 60036003,
            'phone' => '8194186550',
            'email' => 'CONTACT@CLIENT.CA',
            'surety_amount' => '20 000,00 $',
            'respondents' => 'Jean Dupont | Marie Curie | Jean Dupont',
            'authorized_categories' => '[1.23] Électricité | Plomberie | ÉLECTRICITÉ',
            'cautionnement_compagnie' => ['Garantie Nationale'],
        ]]]);

        $response->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.failed', 0);

        $client = Client::where('licence_number', 'L-9080')->firstOrFail();

        $this->assertSame('819-418-6550', $client->phone);
        $this->assertSame('contact@client.ca', $client->email);
        $this->assertSame('20000.00', (string) $client->surety_amount);
        $this->assertSame(['Jean Dupont', 'Marie Curie'], $client->respondents);
        $this->assertSame(['Électricité', 'Plomberie'], $client->authorized_categories);
        $this->assertSame(['Garantie Nationale'], $client->cautionnement_compagnie);
        $this->assertSame('Garantie Nationale', $client->surety_company);
    }

    public function test_structured_object_respondents_are_supported(): void
    {
        $client = $this->import([
            'Licence' => 'L-9090',
            'Répondants / Interlocuteurs (Qualifications)' => [
                ['name' => 'Jean Dupont', 'qualification' => 'Administration'],
                ['nom' => 'Marie Curie', 'role' => 'Sécurité'],
                ['name' => 'Jean Dupont', 'qualification' => 'Administration'], // doublon
            ],
        ]);

        $this->assertSame(
            ['Jean Dupont (Administration)', 'Marie Curie (Sécurité)'],
            $client->respondents
        );
    }
}
