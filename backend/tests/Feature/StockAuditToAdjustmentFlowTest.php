<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\AuditLine;
use App\Models\ItemStock;
use App\Models\StockAdjustment;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The counting chain, driven the way a handheld drives it.
 *
 * EndToEndFlowTest already walks this ground via the Excel bridge. This walks
 * the live route instead — a device posting to the API — and follows one count
 * all the way to the ledger row an auditor would later be shown. The two paths
 * share a service layer but not an entry point, and it is the entry point that
 * a pharmacy actually uses now.
 *
 * The figures are the ones the business stated: 100 held and 95 counted, then
 * 100 held and 108 counted, then a count that matches.
 */
class StockAuditToAdjustmentFlowTest extends TestCase
{
    use RefreshDatabase;

    /** Posts a completed count as a handheld would, and returns its audit line. */
    private function countOnHandheld(float $physical, int $auditNumber = 1): AuditLine
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $this->actingAs($user)->postJson('/api/hht/submissions', [
            'submission_uid' => 'SUB-FLOW-'.$auditNumber,
            'shop_code' => 'PHM001',
            'device_code' => 'HHT-01',
            'audit_number' => $auditNumber,
            'audit_date' => '2026-08-31',
            'hht_user' => 'Karthik Subramani',
            'items' => [[
                'product_code' => 'MED-1001',
                'batch' => 'ABC123',
                'physical_quantity' => $physical,
                'loose_quantity' => 0,
            ]],
        ])->assertCreated();

        return AuditLine::where('product_code', 'MED-1001')
            ->orderByDesc('id')
            ->firstOrFail();
    }

    private function setUpShopWithStock(float $systemQty = 100): ItemStock
    {
        $shop = $this->makeShop();
        $this->makeDevice($shop);
        $item = $this->makeItem();

        return $this->makeStock($shop, $item, $systemQty, 'ABC123');
    }

    public function test_a_shortage_counted_on_a_handheld_closes_the_stock_and_leaves_a_ledger_row(): void
    {
        $stock = $this->setUpShopWithStock(100);
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $line = $this->countOnHandheld(95);

        // What the audit shows before anyone adjusts: the system figure as it
        // stood when counted, the physical figure, and the gap between them.
        $this->assertEquals(100, $line->system_qty);
        $this->assertEquals(95, $line->physical_qty);
        $this->assertEquals(5, $line->variance_qty, 'Five short, and short is positive.');
        $this->assertSame(AuditLine::ADJUSTMENT_NOT_ADJUSTED, $line->adjustment_status);

        $this->actingAs($user)
            ->postJson('/api/adjustments', ['audit_line_id' => $line->id])
            ->assertCreated();

        // The count is the truth: stock now holds what was on the shelf.
        $this->assertEquals(95, $stock->fresh()->system_qty);

        // And the ledger records the movement in full, which is the record an
        // auditor is shown months later when they ask what happened here.
        $posted = StockAdjustment::firstOrFail();

        $this->assertEquals(100, $posted->old_system_qty);
        $this->assertEquals(95, $posted->physical_qty);
        $this->assertEquals(5, $posted->variance_qty);
        $this->assertEquals(95, $posted->new_system_qty);
        $this->assertSame('MED-1001', $posted->product_code);
        $this->assertSame('ABC123', $posted->batch, 'The batch survives the whole chain.');
        $this->assertSame($user->id, $posted->adjusted_by);
        $this->assertNotNull($posted->adjusted_at);

        // Traceable back to the count it came from, and through that to the
        // device and audit that produced it. An adjustment nobody can trace is
        // a number nobody can defend.
        $this->assertSame($line->id, $posted->audit_line_id);
        $this->assertSame($line->audit_id, $posted->audit_id);

        $audit = Audit::findOrFail($line->audit_id);
        $this->assertSame(Audit::SOURCE_API, $audit->source);
        $this->assertNotNull($audit->device_id);
    }

    public function test_an_excess_counted_on_a_handheld_raises_the_stock(): void
    {
        $stock = $this->setUpShopWithStock(100);
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $line = $this->countOnHandheld(108);

        // More on the shelf than the books say: excess is negative.
        $this->assertEquals(-8, $line->variance_qty);

        $this->actingAs($user)
            ->postJson('/api/adjustments', ['audit_line_id' => $line->id])
            ->assertCreated();

        $this->assertEquals(108, $stock->fresh()->system_qty);

        $posted = StockAdjustment::firstOrFail();
        $this->assertEquals(100, $posted->old_system_qty);
        $this->assertEquals(-8, $posted->variance_qty);
        $this->assertEquals(108, $posted->new_system_qty);
    }

    public function test_a_count_that_matches_moves_no_stock(): void
    {
        $stock = $this->setUpShopWithStock(100);
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $line = $this->countOnHandheld(100);

        $this->assertEquals(0, $line->variance_qty);

        // Posting it is harmless and changes nothing, which is the point: a
        // matched count is not an error, it simply has nothing to correct.
        $this->actingAs($user)
            ->postJson('/api/adjustments', ['audit_line_id' => $line->id])
            ->assertCreated();

        $this->assertEquals(100, $stock->fresh()->system_qty, 'Stock is untouched.');
        $this->assertEquals(0, StockAdjustment::firstOrFail()->variance_qty);
    }

    public function test_the_ledger_survives_the_audit_line_being_adjusted(): void
    {
        $this->setUpShopWithStock(100);
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $line = $this->countOnHandheld(95);

        $this->actingAs($user)
            ->postJson('/api/adjustments', ['audit_line_id' => $line->id])
            ->assertCreated();

        // The line is closed out — its variance is now genuinely zero, not
        // merely marked so.
        $adjusted = $line->fresh();
        $this->assertSame(AuditLine::ADJUSTMENT_ADJUSTED, $adjusted->adjustment_status);
        $this->assertEquals(
            0,
            AuditLine::calculateVariance(
                (float) $adjusted->physical_qty,
                (float) $adjusted->loose_qty,
                (float) $adjusted->system_qty
            ),
            'The zero is arithmetic, not an assertion.'
        );

        // But the ledger still remembers what the figures were beforehand.
        // This is the whole reason the history is a separate table rather than
        // a status column: the line moves on, the record does not.
        $posted = StockAdjustment::firstOrFail();
        $this->assertEquals(100, $posted->old_system_qty);
        $this->assertEquals(5, $posted->variance_qty);
    }

    public function test_the_log_row_names_the_posting_the_audit_and_the_movement(): void
    {
        $this->setUpShopWithStock(100);
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $line = $this->countOnHandheld(95);

        $this->actingAs($user)
            ->postJson('/api/adjustments', ['audit_line_id' => $line->id])
            ->assertCreated();

        $row = $this->actingAs($user)->getJson('/api/adjustments')->assertOk()->json('data.0');

        // The identifiers a person quotes, rather than database ids.
        $this->assertMatchesRegularExpression('/^ADJ-\d{6}$/', $row['adjustment_ref']);
        $this->assertSame(Audit::findOrFail($line->audit_id)->reference(), $row['audit_ref']);
        $this->assertSame(Audit::SOURCE_API, $row['source']);

        // The two signed figures mean opposite things and must not be confused:
        // five short on the count, five taken off the books to settle it.
        $this->assertEquals(5, $row['variance_qty'], 'Variance is system minus counted: short is positive.');
        $this->assertEquals(-5, $row['adjustment_qty'], 'The movement is new minus old: stock went down.');
    }

    public function test_the_log_endpoint_returns_the_adjustment_with_its_origins(): void
    {
        $this->setUpShopWithStock(100);
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $line = $this->countOnHandheld(95);

        $this->actingAs($user)
            ->postJson('/api/adjustments', ['audit_line_id' => $line->id])
            ->assertCreated();

        // What the Stock Adj (Audit Log) screen reads. Checked at the API
        // rather than the table, because a ledger the screen cannot fetch is
        // not an audit trail anyone can use.
        $this->actingAs($user)
            ->getJson('/api/adjustments')
            ->assertOk()
            ->assertJsonPath('data.0.product_code', 'MED-1001')
            ->assertJsonPath('data.0.batch', 'ABC123');

        // Compared numerically rather than by string: the resource casts these
        // decimals to numbers, and asserting on their textual form would tie
        // this test to a formatting decision it has no business policing.
        $row = $this->actingAs($user)->getJson('/api/adjustments')->json('data.0');

        $this->assertEquals(100, $row['old_system_qty']);
        $this->assertEquals(95, $row['new_system_qty']);
        $this->assertEquals(5, $row['variance_qty']);
    }
}
