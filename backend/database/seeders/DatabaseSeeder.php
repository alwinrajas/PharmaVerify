<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            AppSettingSeeder::class,
            MasterDataSeeder::class,
            UserSeeder::class,
            DemoAuditSeeder::class,
        ]);
    }
}
