<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoBusinessDataSeeder extends Seeder
{
    public function run(): void
    {
        $categories = collect([
            ['name' => 'residentiel', 'label' => 'Résidentiel'],
            ['name' => 'commercial', 'label' => 'Commercial'],
            ['name' => 'industriel', 'label' => 'Industriel'],
            ['name' => 'institutionnel', 'label' => 'Institutionnel'],
            ['name' => 'renovation', 'label' => 'Rénovation'],
            ['name' => 'electricite', 'label' => 'Électricité'],
            ['name' => 'plomberie', 'label' => 'Plomberie'],
            ['name' => 'structure', 'label' => 'Structure'],
        ])->map(fn (array $category) => Category::updateOrCreate(
            ['name' => $category['name']],
            [
                'label' => $category['label'],
                'description' => "Catégorie {$category['label']} pour les clients démo.",
            ],
        ))->values();

        for ($enterpriseIndex = 1; $enterpriseIndex <= 32; $enterpriseIndex++) {
            $enterprise = Enterprise::updateOrCreate(
                ['name' => sprintf('Entreprise Démo %02d', $enterpriseIndex)],
                [
                    'email' => sprintf('entreprise%02d@demo.rbk.local', $enterpriseIndex),
                    'tax_number' => sprintf('TAX-DEMO-%04d', $enterpriseIndex),
                    'phone' => sprintf('418-555-%04d', 1000 + $enterpriseIndex),
                    'address' => sprintf('%d rue Démo, Québec, QC', 100 + $enterpriseIndex),
                    'status' => 'ACTIVE',
                ],
            );

            $commercialUsers = [];

            for ($commercialIndex = 1; $commercialIndex <= 2; $commercialIndex++) {
                $commercialUser = User::updateOrCreate(
                    ['email' => sprintf('commercial%02d.%d@demo.rbk.local', $enterpriseIndex, $commercialIndex)],
                    [
                        'password_hash' => Hash::make('password'),
                        'role' => 'COMERCIAL',
                        'status' => 'ACTIVE',
                        'first_name' => sprintf('Commercial %d', $commercialIndex),
                        'last_name' => sprintf('Entreprise %02d', $enterpriseIndex),
                        'phone' => sprintf('514-555-%04d', ($enterpriseIndex * 10) + $commercialIndex),
                        'email_verified_at' => now(),
                        'verification_token' => null,
                        'verification_sent_at' => null,
                    ],
                );

                Employee::updateOrCreate(
                    ['user_id' => $commercialUser->id],
                    [
                        'enterprise_id' => $enterprise->id,
                        'first_name' => $commercialUser->first_name,
                        'last_name' => $commercialUser->last_name,
                        'phone' => $commercialUser->phone,
                        'additional_info' => 'Commercial démo généré automatiquement.',
                    ],
                );

                $commercialUsers[] = $commercialUser;
            }

            for ($clientIndex = 1; $clientIndex <= 20; $clientIndex++) {
                $clientCategories = $categories
                    ->slice(($clientIndex + $enterpriseIndex) % max(1, $categories->count()), 3)
                    ->when(
                        fn ($slice) => $slice->count() < 3,
                        fn ($slice) => $slice->merge($categories->take(3 - $slice->count())),
                    )
                    ->values();

                $assignedCommercial = $commercialUsers[($clientIndex - 1) % 2];
                $licenceNumber = sprintf('RBQ-DEMO-%02d-%03d', $enterpriseIndex, $clientIndex);

                Client::updateOrCreate(
                    ['licence_number' => $licenceNumber],
                    [
                        'enterprise_id' => $enterprise->id,
                        'assigned_comercial_id' => $assignedCommercial->id,
                        'categories' => $clientCategories->pluck('label')->all(),
                        'categories_id' => $clientCategories->pluck('id')->all(),
                        'rbq_data' => [
                            'source' => 'demo',
                            'enterprise_index' => $enterpriseIndex,
                            'client_index' => $clientIndex,
                        ],
                        'status' => $clientIndex % 5 === 0 ? 'RESERVED' : 'AVAILABLE',
                        'is_blacklisted' => false,
                        'licence_propre' => $clientIndex % 3 !== 0,
                        'intervenant_name' => sprintf('Client Démo %02d-%02d', $enterpriseIndex, $clientIndex),
                        'licence_status' => 'ACTIVE',
                        'neq' => sprintf('NEQ%02d%05d', $enterpriseIndex, $clientIndex),
                        'full_address' => sprintf('%d avenue Client, Montréal, QC', 2000 + $clientIndex),
                        'municipality' => 'Montréal',
                        'administrative_region' => 'Montréal',
                        'phone' => sprintf('438-555-%04d', ($enterpriseIndex * 20) + $clientIndex),
                        'email' => sprintf('client%02d.%02d@demo.rbk.local', $enterpriseIndex, $clientIndex),
                        'respondent_count' => 1,
                        'respondents' => [
                            ['name' => sprintf('Répondant %02d-%02d', $enterpriseIndex, $clientIndex)],
                        ],
                        'sub_category_count' => $clientCategories->count(),
                        'authorized_categories' => $clientCategories->pluck('label')->all(),
                        'surety_company' => 'Caution Démo',
                        'surety_amount' => 10000 + ($clientIndex * 250),
                        'licence_start_date' => now()->subMonths($clientIndex)->toDateString(),
                        'licence_end_date' => now()->addMonths(12 + $clientIndex)->toDateString(),
                        'representative_name' => $assignedCommercial->first_name . ' ' . $assignedCommercial->last_name,
                    ],
                );
            }
        }
    }
}
