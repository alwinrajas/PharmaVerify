<?php

namespace Tests\Feature;

use App\Models\AuditLine;
use App\Models\StockAdjustment;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The variance convention, in one place.
 *
 * Variance is `System - (Physical + Loose)`: a shortage is positive and an
 * excess negative. Before this convention was confirmed the application stored
 * the negation of it, so these tests exist as much to stop a regression back to
 * the old rule as to prove the new one — a wrong sign here raises no error
 * anywhere, it simply reports a shortage as a surplus.
 */
class VarianceConventionTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------ formula

    public function test_a_shortage_is_positive_and_an_excess_is_negative(): void
    {
        // 100 on the books, 95 on the shelf: five missing.
        $this->assertSame(5.0, AuditLine::calculateVariance(95, 0, 100));

        // 100 on the books, 103 on the shelf: three unaccounted for.
        $this->assertSame(-3.0, AuditLine::calculateVariance(103, 0, 100));

        $this->assertSame(0.0, AuditLine::calculateVariance(40, 0, 40));
    }

    public function test_loose_stock_counts_towards_what_the_shelf_holds(): void
    {
        // The client's own report: 5 held, 4 whole and half a pack loose.
        $this->assertSame(0.5, AuditLine::calculateVariance(4, 0.5, 5));

        // A pack entirely broken open is a legal count and leaves no variance.
        $this->assertSame(0.0, AuditLine::calculateVariance(0, 5, 5));

        // Loose can push a shelf into excess on its own.
        $this->assertSame(-2.0, AuditLine::calculateVariance(10, 2, 10));
    }

    public function test_fractional_quantities_keep_their_precision(): void
    {
        $this->assertSame(0.25, AuditLine::calculateVariance(4.5, 0.25, 5.0));
        $this->assertSame(-0.04, AuditLine::calculateVariance(1.0, 0.04, 1.0));
    }

    public function test_the_counted_total_is_physical_plus_loose(): void
    {
        $this->assertSame(4.5, AuditLine::countedTotal(4, 0.5));
        $this->assertSame(5.0, AuditLine::countedTotal(0, 5));
    }

    // ------------------------------------------------------- recalculation

    public function test_recalculating_a_line_uses_the_confirmed_convention(): void
    {
        $shop = $this->makeShop();
        $device = $this->makeDevice($shop);
        $item = $this->makeItem();

        $audit = \App\Models\Audit::create([
            'shop_id' => $shop->id,
            'device_id' => $device->id,
            'audit_number' => 1,
            'audit_date' => '2026-08-28',
            'item_count' => 1,
            'status' => 'submitted',
        ]);

        $line = AuditLine::create([
            'audit_id' => $audit->id,
            'shop_id' => $shop->id,
            'product_code' => $item->product_code,
            'description' => $item->description,
            'system_qty' => 20,
            'physical_qty' => 12,
            'loose_qty' => 3,
            'variance_qty' => 0,
            'batch' => 'B001',
        ]);

        $line->recalculateVariance();

        // 20 - (12 + 3) = 5 short.
        $this->assertEquals(5, $line->variance_qty);
    }

    // -------------------------------------------------------- the backfill

    public function test_the_recompute_command_puts_historical_figures_on_the_new_convention(): void
    {
        $shop = $this->makeShop();
        $device = $this->makeDevice($shop);
        $item = $this->makeItem();

        $audit = \App\Models\Audit::create([
            'shop_id' => $shop->id,
            'device_id' => $device->id,
            'audit_number' => 1,
            'audit_date' => '2026-08-28',
            'item_count' => 2,
            'status' => 'submitted',
        ]);

        // Written the old way: physical - system.
        $short = AuditLine::create([
            'audit_id' => $audit->id, 'shop_id' => $shop->id,
            'product_code' => 'A', 'description' => 'A',
            'system_qty' => 100, 'physical_qty' => 95, 'loose_qty' => 0,
            'variance_qty' => -5, 'batch' => 'B1',
        ]);

        $excess = AuditLine::create([
            'audit_id' => $audit->id, 'shop_id' => $shop->id,
            'product_code' => 'B', 'description' => 'B',
            'system_qty' => 100, 'physical_qty' => 103, 'loose_qty' => 0,
            'variance_qty' => 3, 'batch' => 'B2',
        ]);

        $matched = AuditLine::create([
            'audit_id' => $audit->id, 'shop_id' => $shop->id,
            'product_code' => 'C', 'description' => 'C',
            'system_qty' => 40, 'physical_qty' => 40, 'loose_qty' => 0,
            'variance_qty' => 0, 'batch' => 'B3',
        ]);

        $this->artisan('variance:recompute')->assertSuccessful();

        $this->assertEquals(5, $short->fresh()->variance_qty);
        $this->assertEquals(-3, $excess->fresh()->variance_qty);
        $this->assertEquals(0, $matched->fresh()->variance_qty);

        // The inputs it read are never written.
        $this->assertEquals(100, $short->fresh()->system_qty);
        $this->assertEquals(95, $short->fresh()->physical_qty);
    }

    public function test_the_recompute_command_is_idempotent(): void
    {
        $shop = $this->makeShop();
        $device = $this->makeDevice($shop);

        $audit = \App\Models\Audit::create([
            'shop_id' => $shop->id, 'device_id' => $device->id,
            'audit_number' => 1, 'audit_date' => '2026-08-28',
            'item_count' => 1, 'status' => 'submitted',
        ]);

        $line = AuditLine::create([
            'audit_id' => $audit->id, 'shop_id' => $shop->id,
            'product_code' => 'A', 'description' => 'A',
            'system_qty' => 100, 'physical_qty' => 95, 'loose_qty' => 0,
            'variance_qty' => -5, 'batch' => 'B1',
        ]);

        $this->artisan('variance:recompute')->assertSuccessful();
        $afterFirst = (float) $line->fresh()->variance_qty;

        // Running it again must not flip it back — this is the property that
        // negating would not have.
        $this->artisan('variance:recompute')->assertSuccessful();
        $this->artisan('variance:recompute')->assertSuccessful();

        $this->assertEquals($afterFirst, (float) $line->fresh()->variance_qty);
        $this->assertEquals(5, $line->fresh()->variance_qty);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $shop = $this->makeShop();
        $device = $this->makeDevice($shop);

        $audit = \App\Models\Audit::create([
            'shop_id' => $shop->id, 'device_id' => $device->id,
            'audit_number' => 1, 'audit_date' => '2026-08-28',
            'item_count' => 1, 'status' => 'submitted',
        ]);

        $line = AuditLine::create([
            'audit_id' => $audit->id, 'shop_id' => $shop->id,
            'product_code' => 'A', 'description' => 'A',
            'system_qty' => 100, 'physical_qty' => 95, 'loose_qty' => 0,
            'variance_qty' => -5, 'batch' => 'B1',
        ]);

        $this->artisan('variance:recompute', ['--dry-run' => true])->assertSuccessful();

        $this->assertEquals(-5, $line->fresh()->variance_qty);
    }

    public function test_the_recompute_corrects_stored_adjustment_figures_too(): void
    {
        $shop = $this->makeShop();
        $device = $this->makeDevice($shop);
        $item = $this->makeItem();
        $stock = $this->makeStock($shop, $item, 100);

        $audit = \App\Models\Audit::create([
            'shop_id' => $shop->id, 'device_id' => $device->id,
            'audit_number' => 1, 'audit_date' => '2026-08-28',
            'item_count' => 1, 'status' => 'submitted',
        ]);

        $line = AuditLine::create([
            'audit_id' => $audit->id, 'shop_id' => $shop->id,
            'item_stock_id' => $stock->id,
            'product_code' => 'A', 'description' => 'A',
            'system_qty' => 100, 'physical_qty' => 95, 'loose_qty' => 0,
            'variance_qty' => 5, 'batch' => 'B1',
        ]);

        $adjustment = StockAdjustment::create([
            'audit_line_id' => $line->id,
            'audit_id' => $audit->id,
            'shop_id' => $shop->id,
            'item_stock_id' => $stock->id,
            'product_code' => 'A',
            'description' => 'A',
            'batch' => 'B1',
            'old_system_qty' => 100,
            'physical_qty' => 95,
            // Stored the old way.
            'variance_qty' => -5,
            'new_system_qty' => 95,
            'adjusted_at' => now(),
        ]);

        $this->artisan('variance:recompute')->assertSuccessful();

        $this->assertEquals(5, $adjustment->fresh()->variance_qty);
        $this->assertEquals(100, $adjustment->fresh()->old_system_qty);
    }

    // --------------------------------------------------------- aggregates

    public function test_the_variance_summary_counts_short_and_excess_the_right_way_round(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = $this->makeShop();
        $device = $this->makeDevice($shop);

        $audit = \App\Models\Audit::create([
            'shop_id' => $shop->id, 'device_id' => $device->id,
            'audit_number' => 1, 'audit_date' => '2026-08-28',
            'item_count' => 3, 'status' => 'submitted',
        ]);

        foreach ([[100.0, 95.0], [100.0, 103.0], [40.0, 40.0]] as $index => [$system, $physical]) {
            AuditLine::create([
                'audit_id' => $audit->id, 'shop_id' => $shop->id,
                'product_code' => 'P'.$index, 'description' => 'P'.$index,
                'system_qty' => $system, 'physical_qty' => $physical, 'loose_qty' => 0,
                'variance_qty' => AuditLine::calculateVariance($physical, 0, $system),
                'batch' => 'B'.$index,
            ]);
        }

        $response = $this->actingAs($user)->getJson('/api/variance/summary')->assertOk();

        $this->assertSame(1, $response->json('data.short_count'));
        $this->assertSame(1, $response->json('data.excess_count'));
        $this->assertSame(1, $response->json('data.matched_count'));

        // Net is +5 short and -3 excess = +2, a net shortage.
        $this->assertEquals(2, $response->json('data.net_variance'));
    }

    public function test_the_short_filter_returns_lines_that_are_actually_short(): void
    {
        $user = $this->userWithRole();
        $shop = $this->makeShop();
        $device = $this->makeDevice($shop);

        $audit = \App\Models\Audit::create([
            'shop_id' => $shop->id, 'device_id' => $device->id,
            'audit_number' => 1, 'audit_date' => '2026-08-28',
            'item_count' => 2, 'status' => 'submitted',
        ]);

        foreach ([['SHORT', 100.0, 95.0], ['OVER', 100.0, 103.0]] as [$code, $system, $physical]) {
            AuditLine::create([
                'audit_id' => $audit->id, 'shop_id' => $shop->id,
                'product_code' => $code, 'description' => $code,
                'system_qty' => $system, 'physical_qty' => $physical, 'loose_qty' => 0,
                'variance_qty' => AuditLine::calculateVariance($physical, 0, $system),
                'batch' => 'B',
            ]);
        }

        $short = $this->actingAs($user)->getJson('/api/variance?variance=short')->assertOk();
        $this->assertCount(1, $short->json('data'));
        $this->assertSame('SHORT', $short->json('data.0.product_code'));

        $excess = $this->actingAs($user)->getJson('/api/variance?variance=excess')->assertOk();
        $this->assertCount(1, $excess->json('data'));
        $this->assertSame('OVER', $excess->json('data.0.product_code'));

        // The older spellings still resolve, so a bookmarked filter keeps working.
        $legacy = $this->actingAs($user)->getJson('/api/variance?variance=positive')->assertOk();
        $this->assertSame('SHORT', $legacy->json('data.0.product_code'));
    }

    // -------------------------------------------------------- adjustment

    public function test_adjusting_posts_the_counted_total_and_leaves_no_residual_variance(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = $this->makeShop();
        $device = $this->makeDevice($shop);
        $item = $this->makeItem();
        $stock = $this->makeStock($shop, $item, 100);

        $audit = \App\Models\Audit::create([
            'shop_id' => $shop->id, 'device_id' => $device->id,
            'audit_number' => 1, 'audit_date' => '2026-08-28',
            'item_count' => 1, 'status' => 'submitted',
        ]);

        $line = AuditLine::create([
            'audit_id' => $audit->id, 'shop_id' => $shop->id,
            'item_stock_id' => $stock->id,
            'product_code' => $item->product_code, 'description' => $item->description,
            'system_qty' => 100, 'physical_qty' => 90, 'loose_qty' => 4,
            'variance_qty' => AuditLine::calculateVariance(90, 4, 100),
            'batch' => 'B001',
            'verification_status' => AuditLine::VERIFICATION_VERIFIED,
        ]);

        // 100 - (90 + 4) = 6 short before adjusting.
        $this->assertEquals(6, $line->variance_qty);

        $this->actingAs($user)->postJson('/api/adjustments', ['audit_line_id' => $line->id])
            ->assertCreated();

        // System stock becomes what was counted in total, loose included —
        // posting 90 alone would leave 4 permanently missing.
        $this->assertEquals(94, $stock->fresh()->system_qty);

        $fresh = $line->fresh();
        $this->assertEquals(94, $fresh->system_qty);
        $this->assertEquals(0, $fresh->variance_qty);

        // And the zero is genuine, not merely asserted.
        $this->assertEquals(0, AuditLine::calculateVariance(
            (float) $fresh->physical_qty,
            (float) $fresh->loose_qty,
            (float) $fresh->system_qty
        ));
    }

    public function test_a_submission_carrying_loose_stock_stores_both_figures_separately(): void
    {
        $user = $this->userWithRole();
        $shop = $this->makeShop();
        $this->makeDevice($shop);
        $item = $this->makeItem();
        $this->makeStock($shop, $item, 50);

        $this->actingAs($user)->postJson('/api/hht/submissions', [
            'submission_uid' => 'SUB-LOOSE-1',
            'shop_code' => 'PHM001',
            'device_code' => 'HHT-01',
            'audit_number' => 1,
            'audit_date' => '2026-08-28',
            'items' => [
                ['product_code' => 'MED-1001', 'physical_quantity' => 45, 'loose_quantity' => 3, 'batch' => 'B001'],
            ],
        ])->assertCreated();

        $line = AuditLine::firstOrFail();

        $this->assertEquals(45, $line->physical_qty);
        $this->assertEquals(3, $line->loose_qty);
        // 50 - (45 + 3) = 2 short.
        $this->assertEquals(2, $line->variance_qty);
    }

    public function test_a_submission_without_a_loose_figure_behaves_exactly_as_before(): void
    {
        $user = $this->userWithRole();
        $shop = $this->makeShop();
        $this->makeDevice($shop);
        $item = $this->makeItem();
        $this->makeStock($shop, $item, 50);

        $this->actingAs($user)->postJson('/api/hht/submissions', [
            'submission_uid' => 'SUB-NOLOOSE-1',
            'shop_code' => 'PHM001',
            'device_code' => 'HHT-01',
            'audit_number' => 1,
            'audit_date' => '2026-08-28',
            'items' => [
                ['product_code' => 'MED-1001', 'physical_quantity' => 45, 'batch' => 'B001'],
            ],
        ])->assertCreated();

        $line = AuditLine::firstOrFail();

        $this->assertEquals(0, $line->loose_qty);
        $this->assertEquals(5, $line->variance_qty);
    }

    /** A verifier can correct the loose figure, and the variance follows. */
    public function test_correcting_the_loose_quantity_recalculates_the_variance(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = $this->makeShop();
        $device = $this->makeDevice($shop);
        $item = $this->makeItem();
        $stock = $this->makeStock($shop, $item, 100);

        $audit = \App\Models\Audit::create([
            'shop_id' => $shop->id, 'device_id' => $device->id,
            'audit_number' => 1, 'audit_date' => '2026-08-28',
            'item_count' => 1, 'status' => 'submitted',
        ]);

        $line = AuditLine::create([
            'audit_id' => $audit->id, 'shop_id' => $shop->id,
            'item_stock_id' => $stock->id,
            'product_code' => $item->product_code, 'description' => $item->description,
            'system_qty' => 100, 'physical_qty' => 96, 'loose_qty' => 0,
            'variance_qty' => 4, 'batch' => 'B001',
        ]);

        $this->actingAs($user)
            ->patchJson('/api/verification/lines/'.$line->id, ['loose_qty' => 4])
            ->assertOk();

        $fresh = $line->fresh();
        $this->assertEquals(4, $fresh->loose_qty);
        $this->assertEquals(0, $fresh->variance_qty);
    }

    /** Nothing about the dashboard breakdown may read the old way round. */
    public function test_the_dashboard_breakdown_reports_short_and_excess_correctly(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = $this->makeShop();
        $device = $this->makeDevice($shop);

        $audit = \App\Models\Audit::create([
            'shop_id' => $shop->id, 'device_id' => $device->id,
            'audit_number' => 1, 'audit_date' => '2026-08-28',
            'item_count' => 3, 'status' => 'submitted',
        ]);

        foreach ([[100.0, 90.0], [100.0, 110.0], [10.0, 10.0]] as $index => [$system, $physical]) {
            AuditLine::create([
                'audit_id' => $audit->id, 'shop_id' => $shop->id,
                'product_code' => 'D'.$index, 'description' => 'D'.$index,
                'system_qty' => $system, 'physical_qty' => $physical, 'loose_qty' => 0,
                'variance_qty' => AuditLine::calculateVariance($physical, 0, $system),
                'batch' => 'B'.$index,
            ]);
        }

        $response = $this->actingAs($user)->getJson('/api/dashboard/summary')->assertOk();

        $this->assertSame(1, $response->json('data.variance_breakdown.short'));
        $this->assertSame(1, $response->json('data.variance_breakdown.excess'));
        $this->assertSame(1, $response->json('data.variance_breakdown.matched'));
    }

    /** The stored column really does hold the sign, not just the display. */
    public function test_the_stored_column_holds_the_confirmed_sign(): void
    {
        $shop = $this->makeShop();
        $device = $this->makeDevice($shop);

        $audit = \App\Models\Audit::create([
            'shop_id' => $shop->id, 'device_id' => $device->id,
            'audit_number' => 1, 'audit_date' => '2026-08-28',
            'item_count' => 1, 'status' => 'submitted',
        ]);

        AuditLine::create([
            'audit_id' => $audit->id, 'shop_id' => $shop->id,
            'product_code' => 'A', 'description' => 'A',
            'system_qty' => 100, 'physical_qty' => 95, 'loose_qty' => 0,
            'variance_qty' => AuditLine::calculateVariance(95, 0, 100),
            'batch' => 'B1',
        ]);

        $stored = DB::table('audit_lines')->value('variance_qty');

        $this->assertEquals(5, $stored);
    }
}
