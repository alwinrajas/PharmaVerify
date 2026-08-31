<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\AuditLine;
use App\Models\Item;
use App\Models\ItemStock;
use App\Models\StockAdjustment;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Counting a shelf from the browser, and what happens to that count afterwards.
 *
 * The point of these is that a browser count is not a parallel system. It ends
 * up as the same Audit and AuditLine rows a handheld produces, so the variance,
 * the adjustment and the ledger all work on it unchanged — which is what makes
 * one shop able to count both ways without two sets of books.
 */
class WebAuditTest extends TestCase
{
    use RefreshDatabase;

    private function shopWithStock(float $qty = 100, string $batch = 'ABC123'): ItemStock
    {
        $shop = $this->makeShop();
        $item = $this->makeItem();

        return $this->makeStock($shop, $item, $qty, $batch);
    }

    public function test_a_browser_audit_opens_with_no_device_and_says_it_came_from_the_system(): void
    {
        $stock = $this->shopWithStock();
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $response = $this->actingAs($user)
            ->postJson('/api/audits/start', ['shop_id' => $stock->shop_id])
            ->assertCreated();

        $audit = Audit::findOrFail($response->json('data.id'));

        // No device, and no invented one. A placeholder here would put a
        // fiction into every report that reads the column.
        $this->assertNull($audit->device_id);
        $this->assertSame(Audit::SOURCE_SYSTEM, $audit->source);
        $this->assertSame(Audit::STATUS_IN_PROGRESS, $audit->status);
        $this->assertSame($user->name, $audit->hht_user);
    }

    public function test_starting_again_resumes_the_open_count_rather_than_splitting_it(): void
    {
        $stock = $this->shopWithStock();
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $first = $this->actingAs($user)->postJson('/api/audits/start', ['shop_id' => $stock->shop_id])->json('data.id');

        // The operator's browser reloaded, or they came back after lunch. The
        // half-counted shelf is work already done; a second audit beside it
        // would split one count across two records that each look complete.
        $second = $this->actingAs($user)->postJson('/api/audits/start', ['shop_id' => $stock->shop_id])->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Audit::count());
    }

    public function test_a_scan_returns_every_batch_the_shop_holds_under_that_code(): void
    {
        $shop = $this->makeShop();
        $item = $this->makeItem();
        $this->makeStock($shop, $item, 100, 'ABC001');
        $this->makeStock($shop, $item, 50, 'ABC002');

        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $response = $this->actingAs($user)
            ->getJson('/api/audits/lookup?shop_id='.$shop->id.'&code=8901234500011')
            ->assertOk();

        // Two batches, and neither is chosen for the operator: which physical
        // box is in their hand is the one fact the server cannot know.
        $this->assertTrue($response->json('data.found'));
        $this->assertCount(2, $response->json('data.matches'));
    }

    public function test_a_scan_does_not_reach_into_another_shops_stock(): void
    {
        $mine = $this->makeShop('PHM001');
        $theirs = $this->makeShop('PHM002');
        $item = $this->makeItem();
        $this->makeStock($theirs, $item, 100, 'ABC001');

        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $response = $this->actingAs($user)
            ->getJson('/api/audits/lookup?shop_id='.$mine->id.'&code=8901234500011')
            ->assertOk();

        // Found in the building, but not in this shop. Showing the other
        // branch's quantity would give the operator a number to count against
        // that describes a shelf they cannot see.
        $this->assertFalse($response->json('data.found'));
        $this->assertSame([], $response->json('data.matches'));
    }

    public function test_an_unknown_code_is_answered_plainly_rather_than_as_an_error(): void
    {
        $stock = $this->shopWithStock();
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $response = $this->actingAs($user)
            ->getJson('/api/audits/lookup?shop_id='.$stock->shop_id.'&code=0000000000')
            ->assertOk();

        $this->assertFalse($response->json('data.found'));
        $this->assertStringContainsString('was not found', $response->json('message'));
        $this->assertSame(0, AuditLine::count(), 'Nothing is recorded for a code that does not exist.');
    }

    public function test_counting_a_product_records_the_system_figure_it_was_counted_against(): void
    {
        $stock = $this->shopWithStock(100);
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $auditId = $this->actingAs($user)
            ->postJson('/api/audits/start', ['shop_id' => $stock->shop_id])
            ->json('data.id');

        $this->actingAs($user)->postJson("/api/audits/{$auditId}/count", [
            'item_stock_id' => $stock->id,
            'physical_qty' => 95,
        ])->assertCreated();

        $line = AuditLine::firstOrFail();

        $this->assertEquals(100, $line->system_qty);
        $this->assertEquals(95, $line->physical_qty);
        $this->assertEquals(5, $line->variance_qty, 'Five short, and short is positive.');
        $this->assertSame('ABC123', $line->batch);
        $this->assertSame($stock->id, $line->item_stock_id);
    }

    public function test_counting_the_same_batch_again_corrects_the_line_instead_of_adding_a_second(): void
    {
        $stock = $this->shopWithStock(100);
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $auditId = $this->actingAs($user)
            ->postJson('/api/audits/start', ['shop_id' => $stock->shop_id])
            ->json('data.id');

        foreach ([95, 97] as $counted) {
            $this->actingAs($user)->postJson("/api/audits/{$auditId}/count", [
                'item_stock_id' => $stock->id,
                'physical_qty' => $counted,
            ])->assertCreated();
        }

        // Two lines for one batch make the audit's totals wrong in a way
        // nobody notices until the variance has already been posted.
        $this->assertSame(1, AuditLine::count());
        $this->assertEquals(97, AuditLine::firstOrFail()->physical_qty);
        $this->assertSame(1, Audit::findOrFail($auditId)->item_count);
    }

    public function test_a_negative_count_is_refused(): void
    {
        $stock = $this->shopWithStock();
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $auditId = $this->actingAs($user)
            ->postJson('/api/audits/start', ['shop_id' => $stock->shop_id])
            ->json('data.id');

        $this->actingAs($user)->postJson("/api/audits/{$auditId}/count", [
            'item_stock_id' => $stock->id,
            'physical_qty' => -1,
        ])->assertStatus(422);

        $this->assertSame(0, AuditLine::count());
    }

    public function test_an_empty_audit_cannot_be_completed(): void
    {
        $stock = $this->shopWithStock();
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $auditId = $this->actingAs($user)
            ->postJson('/api/audits/start', ['shop_id' => $stock->shop_id])
            ->json('data.id');

        // A finished count of nothing is indistinguishable in the review list
        // from a shelf that was genuinely counted and found bare.
        $this->actingAs($user)->postJson("/api/audits/{$auditId}/complete")->assertStatus(422);

        $this->assertSame(Audit::STATUS_IN_PROGRESS, Audit::findOrFail($auditId)->status);
    }

    public function test_a_completed_audit_can_no_longer_be_counted_into(): void
    {
        $stock = $this->shopWithStock(100);
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $auditId = $this->actingAs($user)
            ->postJson('/api/audits/start', ['shop_id' => $stock->shop_id])
            ->json('data.id');

        $this->actingAs($user)->postJson("/api/audits/{$auditId}/count", [
            'item_stock_id' => $stock->id,
            'physical_qty' => 95,
        ])->assertCreated();

        $this->actingAs($user)->postJson("/api/audits/{$auditId}/complete")->assertOk();

        $this->actingAs($user)->postJson("/api/audits/{$auditId}/count", [
            'item_stock_id' => $stock->id,
            'physical_qty' => 90,
        ])->assertStatus(422);

        $this->assertEquals(95, AuditLine::firstOrFail()->physical_qty);
    }

    public function test_a_browser_count_flows_through_variance_to_an_adjusted_stock_and_a_ledger_row(): void
    {
        $stock = $this->shopWithStock(100);
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $auditId = $this->actingAs($user)
            ->postJson('/api/audits/start', ['shop_id' => $stock->shop_id])
            ->json('data.id');

        $this->actingAs($user)->postJson("/api/audits/{$auditId}/count", [
            'item_stock_id' => $stock->id,
            'physical_qty' => 95,
        ])->assertCreated();

        $this->actingAs($user)->postJson("/api/audits/{$auditId}/complete")->assertOk();

        $audit = Audit::findOrFail($auditId);
        $this->assertSame(Audit::STATUS_SUBMITTED, $audit->status);
        $this->assertNotNull($audit->submitted_at);
        $this->assertSame(1, $audit->variance_count);

        // The completed browser audit is now ordinary work for the rest of the
        // application: it appears in variance and adjusts like any other.
        $variance = $this->actingAs($user)->getJson('/api/variance')->assertOk();
        $this->assertSame(1, count($variance->json('data')));

        $line = AuditLine::firstOrFail();

        $this->actingAs($user)
            ->postJson('/api/adjustments', ['audit_line_id' => $line->id])
            ->assertCreated();

        $this->assertEquals(95, $stock->fresh()->system_qty);

        $posted = StockAdjustment::firstOrFail();
        $this->assertEquals(100, $posted->old_system_qty);
        $this->assertEquals(95, $posted->new_system_qty);
        $this->assertEquals(5, $posted->variance_qty);
        $this->assertSame($audit->id, $posted->audit_id);
    }

    public function test_a_shop_user_cannot_count_in_a_shop_they_are_not_assigned_to(): void
    {
        $mine = $this->makeShop('PHM001');
        $theirs = $this->makeShop('PHM002');

        $user = $this->userWithRole(Roles::SHOP_USER);
        $user->shops()->attach($mine->id);

        // Backend-enforced, not merely hidden: naming another shop's id in the
        // request must not open a count in it.
        $this->actingAs($user)
            ->postJson('/api/audits/start', ['shop_id' => $theirs->id])
            ->assertForbidden();

        $this->assertSame(0, Audit::count());
    }

    public function test_a_browser_audit_and_a_handheld_audit_sit_in_the_same_list(): void
    {
        $stock = $this->shopWithStock(100);
        $shop = $stock->shop;
        $this->makeDevice($shop);
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $auditId = $this->actingAs($user)
            ->postJson('/api/audits/start', ['shop_id' => $shop->id])
            ->json('data.id');

        $this->actingAs($user)->postJson("/api/audits/{$auditId}/count", [
            'item_stock_id' => $stock->id,
            'physical_qty' => 95,
        ])->assertCreated();
        $this->actingAs($user)->postJson("/api/audits/{$auditId}/complete")->assertOk();

        $this->actingAs($user)->postJson('/api/hht/submissions', [
            'submission_uid' => 'SUB-MIXED-1',
            'shop_code' => $shop->shop_code,
            'device_code' => 'HHT-01',
            'audit_number' => 99,
            'audit_date' => '2026-08-31',
            'items' => [[
                'product_code' => 'MED-1001',
                'batch' => 'ABC123',
                'physical_quantity' => 90,
            ]],
        ])->assertCreated();

        $listed = $this->actingAs($user)->getJson('/api/audits')->assertOk()->json('data');

        // Both routes, one review list. An operator asking "what was counted
        // in this shop" gets a single answer rather than having to know which
        // way each count arrived.
        $sources = collect($listed)->pluck('source')->sort()->values()->all();
        $this->assertSame([Audit::SOURCE_API, Audit::SOURCE_SYSTEM], $sources);
    }
}
