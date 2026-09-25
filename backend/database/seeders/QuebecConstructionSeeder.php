<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class QuebecConstructionSeeder extends Seeder
{
    private const DEFAULT_PASSWORD = '123456789';

    public function run(): void
    {
        $enterprises = [
            [
                'code' => 'QCN',
                'name' => 'Construction Quebec Nord Inc.',
                'email' => 'admin@construction-quebec-nord.qc.ca',
                'tax_number' => '1234567890',
                'phone' => '418-555-0101',
                'address' => '1500 rue Saint-Jean, Québec, QC G1R 1S6',
                'municipality' => 'Québec',
                'region' => 'Capitale-Nationale',
                'commerciaux' => [
                    ['first_name' => 'Youssef', 'last_name' => 'Ben Ali', 'email' => 'youssef.benali@construction-quebec.tn'],
                    ['first_name' => 'Meriem', 'last_name' => 'Mansouri', 'email' => 'meriem.mansouri@construction-quebec.tn'],
                ],
            ],
            [
                'code' => 'BSL',
                'name' => 'Les Bâtisseurs du St-Laurent',
                'email' => 'admin@batisseurs-stlaurent.qc.ca',
                'tax_number' => '1234567891',
                'phone' => '418-555-0202',
                'address' => '800 boulevard Champlain, Québec, QC G1K 7L7',
                'municipality' => 'Québec',
                'region' => 'Capitale-Nationale',
                'commerciaux' => [
                    ['first_name' => 'Ahmed', 'last_name' => 'Trabelsi', 'email' => 'ahmed.trabelsi@batisseurs-stlaurent.tn'],
                    ['first_name' => 'Cyrine', 'last_name' => 'Gharbi', 'email' => 'cyrine.gharbi@batisseurs-stlaurent.tn'],
                ],
            ],
            [
                'code' => 'APS',
                'name' => 'Apex Structures Québec',
                'email' => 'admin@apex-structures-qc.ca',
                'tax_number' => '1234567892',
                'phone' => '418-555-0303',
                'address' => '2200 avenue Pasteur, Québec, QC G2E 4H5',
                'municipality' => 'Québec',
                'region' => 'Capitale-Nationale',
                'commerciaux' => [
                    ['first_name' => 'Mohamed', 'last_name' => 'Khemir', 'email' => 'mohamed.khemir@apex-structures.tn'],
                    ['first_name' => 'Olfa', 'last_name' => 'Hammami', 'email' => 'olfa.hammami@apex-structures.tn'],
                ],
            ],
        ];

        foreach ($enterprises as $index => $data) {
            $enterprise = Enterprise::updateOrCreate(
                ['name' => $data['name']],
                [
                    'email' => $data['email'],
                    'tax_number' => $data['tax_number'],
                    'phone' => $data['phone'],
                    'address' => $data['address'],
                    'status' => 'ACTIVE',
                ],
            );

            foreach ($data['commerciaux'] as $commercialIndex => $commercial) {
                $email = $commercial['email'] ?? sprintf('commercial.%s.%d@construction-demo.qc.ca', strtolower($data['code']), $commercialIndex + 1);

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
            }
        }
    }

    private function phone(int $enterpriseIndex, int $commercialIndex): string
    {
        $areaCodes = ['418', '514', '450'];

        return sprintf('%s-555-%04d', $areaCodes[$enterpriseIndex % count($areaCodes)], 900 + ($enterpriseIndex * 10) + $commercialIndex);
    }
}
