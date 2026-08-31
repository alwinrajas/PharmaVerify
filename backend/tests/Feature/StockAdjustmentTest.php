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

    /**
     * Variance = System - (Physical + Loose). The wider set of cases, including
     * loose stock and the historical backfill, lives in
     * {@see VarianceConventionTest}; these are the ones this suite relies on.
     */
    public function test_variance_is_system_minus_what_was_counted(): void
    {
        $this->assertEquals(5, AuditLine::calculateVariance(95, 0, 100));
        $this->assertEquals(-3, AuditLine::calculateVariance(103, 0, 100));
        $this->assertEquals(0, AuditLine::calculateVariance(100, 0, 100));
        $this->assertEquals(-0.5, AuditLine::calculateVariance(100.5, 0, 100));
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
        $this->assertEquals(5, $adjustment->variance_qty);
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
            'variance_qty' => -24,
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

    /**
     * Stock moved between the count and the adjustment: the audit saw 100, the
     * shop now holds 110. Posting without acknowledging that must be refused,
     * and refusing must leave both the stock and the ledger untouched — a
     * concurrency conflict is not allowed to make a silent, partial change.
     */
    public function test_drift_between_the_audited_and_current_stock_is_rejected(): void
    {
        [$user, $line, $stock] = $this->countedShortByFive();
        $stock->update(['system_qty' => 110]);

        $response = $this->actingAs($user)->postJson('/api/adjustments', [
            'audit_line_id' => $line->id,
        ]);

        $response->assertStatus(422);
        $message = $response->json('message');
        $this->assertStringContainsString('has changed since this audit was counted', $message);
        $this->assertStringContainsString('audit saw 100', $message);
        $this->assertStringContainsString('stock now holds 110', $message);

        $this->assertEquals(110, $stock->fresh()->system_qty, 'A refused adjustment must not touch stock.');
        $this->assertSame(0, StockAdjustment::count());
        $this->assertSame(AuditLine::ADJUSTMENT_NOT_ADJUSTED, $line->fresh()->adjustment_status);
    }

    /** Acknowledging the drift proceeds, and the ledger keeps a record of it. */
    public function test_acknowledging_the_drift_proceeds_and_records_it_in_the_reason(): void
    {
        [$user, $line, $stock] = $this->countedShortByFive();
        $stock->update(['system_qty' => 110]);

        $response = $this->actingAs($user)->postJson('/api/adjustments', [
            'audit_line_id' => $line->id,
            'reason' => 'Physical count confirmed by supervisor',
            'acknowledge_drift' => true,
        ]);

        $response->assertCreated();

        // The counted total, 95, replaces whatever stock held at the moment of
        // adjustment — the count remains the truth once the drift is seen.
        $this->assertEquals(95, $stock->fresh()->system_qty);

        $adjustment = StockAdjustment::firstOrFail();
        $this->assertEquals(110, $adjustment->old_system_qty);
        $this->assertEquals(95, $adjustment->new_system_qty);
        $this->assertStringContainsString('Physical count confirmed by supervisor', $adjustment->reason);
        $this->assertStringContainsString('Stock had changed from 100 to 110 before this adjustment.', $adjustment->reason);
    }

    /** Regression guard: acknowledging drift does not reopen a line already adjusted. */
    public function test_a_second_adjustment_is_still_rejected_even_with_acknowledge_drift(): void
    {
        [$user, $line] = $this->countedShortByFive();

        $this->actingAs($user)->postJson('/api/adjustments', ['audit_line_id' => $line->id])->assertCreated();

        $response = $this->actingAs($user)->postJson('/api/adjustments', [
            'audit_line_id' => $line->id,
            'acknowledge_drift' => true,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('already been adjusted', $response->json('message'));
        $this->assertSame(1, StockAdjustment::count());
    }

    /** A line with no variance still adjusts cleanly, with nothing to acknowledge. */
    public function test_a_zero_variance_line_adjusts_without_error(): void
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
            'physical_qty' => 100,
            'variance_qty' => 0,
            'batch' => 'B001',
        ]);

        $response = $this->actingAs($user)->postJson('/api/adjustments', ['audit_line_id' => $line->id]);

        $response->assertCreated();
        $this->assertEquals(100, $stock->fresh()->system_qty);
        $this->assertEquals(0, StockAdjustment::firstOrFail()->variance_qty);
        $this->assertEquals(0, $line->fresh()->variance_qty);
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
            'variance_qty' => 5,
            'batch' => 'B001',
        ]);

        return [$user, $line, $stock];
    }
}
