<?php

namespace Database\Seeders;

use App\Models\Shop;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name' => 'Arun Prakash',
                'email' => 'admin@pharmaverify.com',
                'employee_code' => 'EMP-1001',
                'phone' => '+91 98400 11001',
                'role' => Roles::ADMINISTRATOR,
                'shops' => [],
            ],
            [
                'name' => 'Divya Ramesh',
                'email' => 'supervisor@pharmaverify.com',
                'employee_code' => 'EMP-1002',
                'phone' => '+91 98400 11002',
                'role' => Roles::SUPERVISOR,
                'shops' => [],
            ],
            [
                'name' => 'Karthik Subramani',
                'email' => 'annanagar@pharmaverify.com',
                'employee_code' => 'EMP-2001',
                'phone' => '+91 98400 22001',
                'role' => Roles::SHOP_USER,
                'shops' => ['PHM001'],
            ],
            [
                'name' => 'Meena Lakshmi',
                'email' => 'tnagar@pharmaverify.com',
                'employee_code' => 'EMP-2002',
                'phone' => '+91 98400 22002',
                'role' => Roles::SHOP_USER,
                'shops' => ['PHM002', 'PHM003'],
            ],
        ];

        foreach ($users as $definition) {
            $user = User::updateOrCreate(
                ['email' => $definition['email']],
                [
                    'name' => $definition['name'],
                    'password' => 'Pharma@2026',
                    'employee_code' => $definition['employee_code'],
                    'phone' => $definition['phone'],
                    'status' => 'active',
                ]
            );

            $user->syncRoles([$definition['role']]);

            if ($definition['shops'] !== []) {
                $shopIds = Shop::whereIn('shop_code', $definition['shops'])->pluck('id');
                $user->shops()->sync($shopIds);
            }
        }
    }
}
