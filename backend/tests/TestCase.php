<?php

namespace Tests;

use App\Models\Device;
use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Shop;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;

abstract class TestCase extends BaseTestCase
{
    protected function seedRoles(): void
    {
        $this->seed(RolePermissionSeeder::class);
    }

    protected function userWithRole(string $role = Roles::ADMINISTRATOR, array $attributes = []): User
    {
        $this->seedRoles();

        $user = User::create(array_merge([
            'name' => 'Test '.$role,
            'email' => str()->random(8).'@pharmaverify.test',
            'password' => 'Pharma@2026',
            'status' => 'active',
        ], $attributes));

        $user->assignRole($role);

        return $user->fresh();
    }

    protected function makeShop(string $code = 'PHM001'): Shop
    {
        return Shop::create([
            'shop_code' => $code,
            'shop_name' => 'Wellness Pharmacy '.$code,
            'status' => 'active',
        ]);
    }

    protected function makeDevice(Shop $shop, string $code = 'HHT-01'): Device
    {
        return Device::create([
            'shop_id' => $shop->id,
            'device_code' => $code,
            'status' => 'active',
        ]);
    }

    protected function makeItem(string $productCode = 'MED-1001', string $barcode = '8901234500011'): Item
    {
        return Item::create([
            'product_code' => $productCode,
            'barcode' => $barcode,
            'description' => 'Paracetamol 500mg Tablet',
            'uom' => 'STRIP',
            'price' => 32.5,
            'status' => 'active',
        ]);
    }

    protected function makeStock(Shop $shop, Item $item, float $qty = 100, string $batch = 'B001'): ItemStock
    {
        return ItemStock::create([
            'shop_id' => $shop->id,
            'item_id' => $item->id,
            'product_code' => $item->product_code,
            'barcode' => $item->barcode,
            'description' => $item->description,
            'system_qty' => $qty,
            'uom' => $item->uom,
            'price' => $item->price,
            'batch' => $batch,
            'expiry_date' => Carbon::create(2027, 6, 30)->toDateString(),
            'shelf_location' => 'A-01',
            'verification_status' => 'not_verified',
        ]);
    }
}
