<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\AuditLine;
use App\Models\ItemStock;
use App\Models\StockAdjustment;
use App\Models\StockTake;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_variance_is_physical_minus_system(): void
    {
        $this->assertEquals(-5, AuditLine::calculateVariance(95, 100));
        $this->assertEquals(3, AuditLine::calculateVariance(103, 100));
        $this->assertEquals(0, AuditLine::calculateVariance(100, 100));
        $this->assertEquals(0.5, AuditLine::calculateVariance(100.5, 100));
    }

    public function test_saving_an_adjustment_updates_system_stock_immediately(): void
    {
        [$user, $line, $stock] = $this->countedShortByFive();

        $response = $this->actingAs($user)->postJson('/api/adjustments', [
            'audit_line_id' => $line->id,
            'reason' => 'Physical count confirmed by supervisor',
        ]);

        $response->assertCreated();

        // No approval step: the stock has already changed.
        $this->assertEquals(95, $stock->fresh()->system_qty);

        $adjustment = StockAdjustment::firstOrFail();
        $this->assertEquals(100, $adjustment->old_system_qty);
        $this->assertEquals(95, $adjustment->physical_qty);
        $this->assertEquals(-5, $adjustment->variance_qty);
        $this->assertEquals(95, $adjustment->new_system_qty);
        $this->assertEquals($user->id, $adjustment->adjusted_by);
        $this->assertNotNull($adjustment->adjusted_at);
        $this->assertSame('Physical count confirmed by supervisor', $adjustment->reason);
    }

    public function test_an_adjusted_line_is_marked_and_its_variance_cleared(): void
    {
        [$user, $line] = $this->countedShortByFive();

        $this->actingAs($user)->postJson('/api/adjustments', ['audit_line_id' => $line->id])->assertCreated();

        $line = $line->fresh();

        $this->assertSame(AuditLine::ADJUSTMENT_ADJUSTED, $line->adjustment_status);
        $this->assertSame(AuditLine::VERIFICATION_VERIFIED, $line->verification_status);
        $this->assertEquals(0, $line->variance_qty);
        $this->assertEquals(95, $line->system_qty);
        $this->assertNotNull($line->adjusted_at);
    }

    public function test_the_same_line_cannot_be_adjusted_twice(): void
    {
        [$user, $line, $stock] = $this->countedShortByFive();

        $this->actingAs($user)->postJson('/api/adjustments', ['audit_line_id' => $line->id])->assertCreated();

        $response = $this->actingAs($user)->postJson('/api/adjustments', ['audit_line_id' => $line->id]);

        $response->assertStatus(422);
        $this->assertStringContainsString('already been adjusted', $response->json('message'));

        $this->assertSame(1, StockAdjustment::count());
        $this->assertEquals(95, $stock->fresh()->system_qty, 'A refused retry must not change stock again.');
    }

    public function test_a_product_missing_from_the_stock_file_cannot_be_adjusted(): void
    {
        $user = $this->userWithRole(Roles::SUPERVISOR);
        $shop = $this->makeShop();
        $device = $this->makeDevice($shop);

        $audit = Audit::create([
            'shop_id' => $shop->id,
            'device_id' => $device->id,
            'audit_number' => 1,
            'audit_date' => '2026-08-25',
            'submitted_at' => now(),
            'item_count' => 1,
            'status' => Audit::STATUS_SUBMITTED,
        ]);

        $line = AuditLine::create([
            'audit_id' => $audit->id,
            'shop_id' => $shop->id,
            'item_stock_id' => null,
            'barcode' => '8901234599999',
            'description' => 'Rabeprazole 20mg Tablet',
            'system_qty' => 0,
            'physical_qty' => 24,
            'variance_qty' => 24,
            'is_unknown_item' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/adjustments', ['audit_line_id' => $line->id]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Stock Take', $response->json('message'));
        $this->assertSame(0, StockAdjustment::count());
    }

    public function test_a_shop_user_cannot_post_an_adjustment(): void
    {
        [, $line, $stock] = $this->countedShortByFive();

        $shopUser = $this->userWithRole(Roles::SHOP_USER);
        $shopUser->shops()->attach($line->shop_id);

        $this->actingAs($shopUser)
            ->postJson('/api/adjustments', ['audit_line_id' => $line->id])
            ->assertStatus(403);

        $this->assertEquals(100, $stock->fresh()->system_qty);
        $this->assertSame(0, StockAdjustment::count());
    }

    public function test_recording_a_stock_take_does_not_create_an_item_master_record(): void
    {
        $user = $this->userWithRole(Roles::SUPERVISOR);
        $shop = $this->makeShop();
        $itemsBefore = \App\Models\Item::count();

        $this->actingAs($user)->postJson('/api/stock-takes', [
            'shop_id' => $shop->id,
            'barcode' => '8901234599999',
            'product_code' => 'MED-1099',
            'description' => 'Rabeprazole 20mg Tablet',
            'physical_qty' => 24,
            'batch' => 'BX2210',
            'shelf_location' => 'B-02',
        ])->assertCreated();

        $this->assertSame(1, StockTake::count());
        $this->assertSame($itemsBefore, \App\Models\Item::count(), 'Item master must be untouched.');
        $this->assertSame(0, ItemStock::count(), 'A stock take is not stock.');
    }

    /**
     * A shop counted five short on one product.
     *
     * @return array{0: \App\Models\User, 1: AuditLine, 2: ItemStock}
     */
    private function countedShortByFive(): array
    {
        $user = $this->userWithRole(Roles::SUPERVISOR);
        $shop = $this->makeShop();
        $device = $this->makeDevice($shop);
        $item = $this->makeItem();
        $stock = $this->makeStock($shop, $item, 100);

        $audit = Audit::create([
            'shop_id' => $shop->id,
            'device_id' => $device->id,
            'audit_number' => 1,
            'audit_date' => '2026-08-25',
            'submitted_at' => now(),
            'item_count' => 1,
            'variance_count' => 1,
            'status' => Audit::STATUS_SUBMITTED,
        ]);

        $line = AuditLine::create([
            'audit_id' => $audit->id,
            'shop_id' => $shop->id,
            'item_stock_id' => $stock->id,
            'product_code' => $item->product_code,
            'barcode' => $item->barcode,
            'description' => $item->description,
            'system_qty' => 100,
            'physical_qty' => 95,
            'variance_qty' => -5,
            'batch' => 'B001',
        ]);

        return [$user, $line, $stock];
    }
}
