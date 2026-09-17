<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class QuebecConstructionSeeder extends Seeder
{
    private const DEFAULT_PASSWORD = 'password';

    private const CLIENTS_PER_ENTERPRISE = 50;

    public function run(): void
    {
        $categories = $this->seedCategories();

        $enterprises = [
            [
                'code' => 'BOR',
                'name' => 'Construction Boréal inc.',
                'email' => 'admin@construction-boreal.qc.ca',
                'tax_number' => '1149283746',
                'phone' => '418-555-0101',
                'address' => '1245 boulevard Wilfrid-Hamel, Québec, QC G1N 3Y1',
                'municipality' => 'Québec',
                'region' => 'Capitale-Nationale',
                'admin' => ['first_name' => 'Éric', 'last_name' => 'Tremblay'],
                'commerciaux' => [
                    ['first_name' => 'Marie', 'last_name' => 'Gagnon'],
                    ['first_name' => 'Luc', 'last_name' => 'Bouchard'],
                ],
            ],
            [
                'code' => 'LAJ',
                'name' => 'Les Entreprises Lajeunesse ltée',
                'email' => 'admin@entreprises-lajeunesse.qc.ca',
                'tax_number' => '1193847562',
                'phone' => '514-555-0202',
                'address' => '5200 rue Saint-Denis, Montréal, QC H2J 2M1',
                'municipality' => 'Montréal',
                'region' => 'Montréal',
                'admin' => ['first_name' => 'Sophie', 'last_name' => 'Lévesque'],
                'commerciaux' => [
                    ['first_name' => 'Jean-François', 'last_name' => 'Roy'],
                    ['first_name' => 'Catherine', 'last_name' => 'Fortin'],
                ],
            ],
            [
                'code' => 'GAG',
                'name' => 'Excavation Gagnon & Fils',
                'email' => 'admin@excavation-gagnon.qc.ca',
                'tax_number' => '1172638495',
                'phone' => '450-555-0303',
                'address' => '875 boulevard Curé-Labelle, Laval, QC H7V 2V5',
                'municipality' => 'Laval',
                'region' => 'Laval',
                'admin' => ['first_name' => 'Patrick', 'last_name' => 'Gagnon'],
                'commerciaux' => [
                    ['first_name' => 'Nathalie', 'last_name' => 'Côté'],
                    ['first_name' => 'Mathieu', 'last_name' => 'Bergeron'],
                ],
            ],
        ];

        foreach ($enterprises as $index => $data) {
            $admin = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'password_hash' => Hash::make(self::DEFAULT_PASSWORD),
                    'role' => 'ENTREPRISE',
                    'status' => 'ACTIVE',
                    'first_name' => $data['admin']['first_name'],
                    'last_name' => $data['admin']['last_name'],
                    'phone' => $data['phone'],
                    'email_verified_at' => now(),
                    'verification_token' => null,
                    'verification_sent_at' => null,
                ],
            );

            $enterprise = Enterprise::updateOrCreate(
                ['user_id' => $admin->id],
                [
                    'name' => $data['name'],
                    'tax_number' => $data['tax_number'],
                    'phone' => $data['phone'],
                    'address' => $data['address'],
                ],
            );

            $commerciaux = [];

            foreach ($data['commerciaux'] as $commercialIndex => $commercial) {
                $email = sprintf('commercial.%s.%d@construction-demo.qc.ca', strtolower($data['code']), $commercialIndex + 1);

                $commercialUser = User::updateOrCreate(
                    ['email' => $email],
                    [
                        'password_hash' => Hash::make(self::DEFAULT_PASSWORD),
                        'role' => 'COMERCIAL',
                        'status' => 'ACTIVE',
                        'first_name' => $commercial['first_name'],
                        'last_name' => $commercial['last_name'],
                        'phone' => $this->phone($index, $commercialIndex + 1),
                        'email_verified_at' => now(),
                        'verification_token' => null,
                        'verification_sent_at' => null,
                    ],
                );

                Employee::updateOrCreate(
                    ['user_id' => $commercialUser->id],
                    [
                        'enterprise_id' => $enterprise->id,
                        'first_name' => $commercial['first_name'],
                        'last_name' => $commercial['last_name'],
                        'phone' => $commercialUser->phone,
                        'additional_info' => sprintf('Commercial - %s', $data['name']),
                    ],
                );

                $commerciaux[] = $commercialUser;
            }

            for ($clientIndex = 1; $clientIndex <= self::CLIENTS_PER_ENTERPRISE; $clientIndex++) {
                $commercial = $commerciaux[($clientIndex - 1) % count($commerciaux)];

                $this->seedClient($enterprise, $data, $index, $clientIndex, $commercial, $categories);
            }
        }
    }

    private function seedClient(Enterprise $enterprise, array $data, int $enterpriseIndex, int $clientIndex, User $commercial, $categories): void
    {
        $prefixes = [
            'Construction', 'Excavation', 'Toiture', 'Plomberie', 'Électricité',
            'Maçonnerie', 'Charpenterie', 'Rénovation', 'Béton', 'Peinture',
            'Couvreur', 'CVC', 'Portes et fenêtres', 'Céramique', 'Paysagement',
            'Fondations', 'Structure', 'Isolation', 'Génie civil', 'Déneigement',
        ];

        $families = [
            'Tremblay', 'Gagnon', 'Roy', 'Côté', 'Bouchard', 'Gauthier', 'Morin',
            'Lavoie', 'Fortin', 'Bergeron', 'Ouellet', 'Pelletier', 'Bélanger',
            'Lévesque', 'Desjardins', 'Girard', 'Simard', 'Boucher', 'Caron',
            'Beaulieu', 'Cloutier', 'Dubé', 'Poirier', 'Fournier', 'Nadeau',
            'Lapointe', 'Leclerc', 'Dion', 'Tardif', 'Hébert', 'Landry',
            'Turcotte', 'Paquette', 'Thibault', 'Lessard', 'Bérubé', 'Vachon',
            'Perron', 'Rousseau', 'Gagné', 'Bernier', 'Couture', 'Blais',
            'Champagne', 'Marois', 'Pépin', 'Villeneuve', 'Paradis', 'Rodrigue', 'Bastien',
        ];

        $suffixes = ['inc.', 'ltée', 'enr.', 'et fils', 'et associés', '& frères', 'senc'];

        $locations = [
            ['Québec', 'Capitale-Nationale'],
            ['Montréal', 'Montréal'],
            ['Laval', 'Laval'],
            ['Gatineau', 'Outaouais'],
            ['Sherbrooke', 'Estrie'],
            ['Trois-Rivières', 'Mauricie'],
            ['Saguenay', 'Saguenay-Lac-Saint-Jean'],
            ['Longueuil', 'Montérégie'],
            ['Lévis', 'Chaudière-Appalaches'],
            ['Drummondville', 'Centre-du-Québec'],
            ['Saint-Jérôme', 'Laurentides'],
            ['Repentigny', 'Lanaudière'],
            ['Rimouski', 'Bas-Saint-Laurent'],
            ['Rouyn-Noranda', 'Abitibi-Témiscamingue'],
            ['Sept-Îles', 'Côte-Nord'],
            ['Gaspé', 'Gaspésie-Îles-de-la-Madeleine'],
            ['Granby', 'Estrie'],
            ['Shawinigan', 'Mauricie'],
            ['Victoriaville', 'Centre-du-Québec'],
            ['Val-d\'Or', 'Abitibi-Témiscamingue'],
        ];

        $areaCodes = ['418', '514', '438', '450', '819', '873'];

        $suretyCompanies = [
            'Assurances Construction Québec',
            'Cautionnement Boréal inc.',
            'Garantie RBQ',
            'Assurance Chantier ltée',
        ];

        $prefix = $prefixes[(($clientIndex - 1) + ($enterpriseIndex * 7)) % count($prefixes)];
        $family = $families[(($clientIndex - 1) * 3 + ($enterpriseIndex * 11)) % count($families)];
        $suffix = $suffixes[(($clientIndex - 1) + $enterpriseIndex) % count($suffixes)];
        $name = sprintf('%s %s %s', $prefix, $family, $suffix);

        [$municipality, $region] = $locations[(($clientIndex - 1) + ($enterpriseIndex * 5)) % count($locations)];

        $clientCategories = $categories
            ->slice(($clientIndex + $enterpriseIndex) % max(1, $categories->count()), 3)
            ->when(
                fn ($slice) => $slice->count() < 3,
                fn ($slice) => $slice->merge($categories->take(3 - $slice->count())),
            )
            ->values();

        $intervenant = sprintf('%s %s', $this->firstName($clientIndex), $family);

        Client::updateOrCreate(
            ['licence_number' => sprintf('RBQ-%s-%03d', $data['code'], $clientIndex)],
            [
                'categories' => $clientCategories->pluck('label')->all(),
                'categories_id' => $clientCategories->pluck('id')->all(),
                'rbq_data' => [
                    'name' => $name,
                    'source' => 'quebec-construction',
                    'entreprise_id' => $enterprise->id,
                    'entreprise_name' => $enterprise->name,
                    'entreprise_index' => $enterpriseIndex + 1,
                    'client_index' => $clientIndex,
                ],
                'status' => 'AVAILABLE',
                'is_blacklisted' => false,
                'licence_propre' => true,
                'intervenant_name' => $intervenant,
                'licence_status' => 'ACTIVE',
                'neq' => sprintf('11%03d%05d', $enterpriseIndex + 1, $clientIndex),
                'full_address' => sprintf('%d %s, %s, QC', 100 + (($clientIndex * 13 + $enterpriseIndex) % 3000), $this->street($clientIndex + $enterpriseIndex), $municipality),
                'municipality' => $municipality,
                'administrative_region' => $region,
                'phone' => sprintf('%s-555-%04d', $areaCodes[($clientIndex + $enterpriseIndex) % count($areaCodes)], ($enterpriseIndex * 100) + $clientIndex),
                'email' => sprintf('client%d.%02d@construction-demo.qc.ca', $enterpriseIndex + 1, $clientIndex),
                'respondent_count' => 1,
                'respondents' => [
                    ['name' => $intervenant, 'role' => 'Président'],
                ],
                'sub_category_count' => $clientCategories->count(),
                'authorized_categories' => $clientCategories->pluck('label')->all(),
                'surety_company' => $suretyCompanies[($clientIndex + $enterpriseIndex) % count($suretyCompanies)],
                'surety_amount' => 15000 + ($clientIndex * 500),
                'licence_start_date' => now()->subMonths($clientIndex)->toDateString(),
                'licence_end_date' => now()->addYear()->addMonths($clientIndex % 12)->toDateString(),
                'representative_name' => trim($commercial->first_name.' '.$commercial->last_name),
            ],
        );
    }

    private function seedCategories()
    {
        $definitions = [
            ['name' => 'residentiel', 'label' => 'Résidentiel'],
            ['name' => 'commercial', 'label' => 'Commercial'],
            ['name' => 'industriel', 'label' => 'Industriel'],
            ['name' => 'institutionnel', 'label' => 'Institutionnel'],
            ['name' => 'renovation', 'label' => 'Rénovation'],
            ['name' => 'electricite', 'label' => 'Électricité'],
            ['name' => 'plomberie', 'label' => 'Plomberie'],
            ['name' => 'maconnerie', 'label' => 'Maçonnerie'],
            ['name' => 'charpenterie', 'label' => 'Charpenterie'],
            ['name' => 'toiture', 'label' => 'Toiture'],
            ['name' => 'excavation', 'label' => 'Excavation'],
            ['name' => 'beton', 'label' => 'Béton'],
            ['name' => 'peinture', 'label' => 'Peinture'],
            ['name' => 'ceramique', 'label' => 'Céramique'],
            ['name' => 'cvc', 'label' => 'Chauffage, ventilation et climatisation'],
            ['name' => 'structure', 'label' => 'Structure'],
            ['name' => 'fondation', 'label' => 'Fondation'],
            ['name' => 'isolation', 'label' => 'Isolation'],
            ['name' => 'paysagement', 'label' => 'Paysagement'],
            ['name' => 'generale', 'label' => 'Entrepreneur général'],
        ];

        return collect($definitions)
            ->map(fn (array $category) => Category::updateOrCreate(
                ['name' => $category['name']],
                [
                    'label' => $category['label'],
                    'description' => sprintf('Catégorie %s — clients construction du Québec.', $category['label']),
                ],
            ))
            ->values();
    }

    private function phone(int $enterpriseIndex, int $commercialIndex): string
    {
        $areaCodes = ['418', '514', '450'];

        return sprintf('%s-555-%04d', $areaCodes[$enterpriseIndex % count($areaCodes)], 900 + ($enterpriseIndex * 10) + $commercialIndex);
    }

    private function firstName(int $index): string
    {
        $names = [
            'Jean', 'Pierre', 'Michel', 'André', 'Claude', 'Sylvie', 'Nathalie',
            'Isabelle', 'Chantal', 'Julie', 'Martin', 'Stéphane', 'Alain',
            'Daniel', 'François', 'Manon', 'Suzanne', 'Diane', 'Louise', 'Robert',
        ];

        return $names[($index - 1) % count($names)];
    }

    private function street(int $seed): string
    {
        $streets = [
            'rue Principale', 'avenue du Port', 'boulevard Industriel',
            'rue de la Métropole', 'avenue Cartier', 'rue Saint-Laurent',
            'boulevard Curé-Labelle', 'rue Notre-Dame', 'avenue Royale',
            'rue de la Construction', 'chemin de la Rivière', 'rue des Bâtisseurs',
        ];

        return $streets[$seed % count($streets)];
    }
}
