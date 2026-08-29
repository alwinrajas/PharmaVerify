<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\StockTake;
use App\Models\StockTakeSession;
use App\Support\Roles;
use App\Support\SessionReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Audit and stock-take references.
 *
 * The handheld numbers `AUD-ddMMyyyy-NNNN` and `STK-ddMMyyyy-NNNN`, per shop
 * and never reused. PharmaVerify records those and derives one for audits that
 * predate the format, without letting either displace the identity it already
 * enforces.
 */
class SessionNumberingTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------ format

    public function test_a_reference_is_built_day_first_with_a_four_digit_sequence(): void
    {
        $this->assertSame('AUD-11082026-0002', SessionReference::forAudit('2026-08-11', 2));
        $this->assertSame('STK-17082026-0007', SessionReference::forStockTake('2026-08-17', 7));

        // Four digits, so the sequence sorts as text as well as it reads.
        $this->assertSame('AUD-01012026-0001', SessionReference::forAudit('2026-01-01', 1));
        $this->assertSame('AUD-31122026-1234', SessionReference::forAudit('2026-12-31', 1234));
    }

    public function test_a_reference_is_recognised_and_taken_apart(): void
    {
        $this->assertTrue(SessionReference::isValid('AUD-11082026-0002'));
        $this->assertTrue(SessionReference::isValid('STK-17082026-0007', SessionReference::STOCK_TAKE));

        // The prefix has to agree with the kind being asked about.
        $this->assertFalse(SessionReference::isValid('STK-17082026-0007', SessionReference::AUDIT));

        $this->assertFalse(SessionReference::isValid('AUD-2026-08-11-2'));
        $this->assertFalse(SessionReference::isValid('AUDIT-11082026-0002'));
        $this->assertFalse(SessionReference::isValid(''));

        $this->assertSame(
            ['prefix' => 'AUD', 'date' => '11082026', 'sequence' => 2],
            SessionReference::parse('AUD-11082026-0002')
        );
        $this->assertSame(7, SessionReference::sequenceOf('STK-17082026-0007'));
        $this->assertNull(SessionReference::sequenceOf('not a reference'));
    }

    // ------------------------------------------------------------- audits

    public function test_an_audit_derives_a_reference_when_it_has_none(): void
    {
        $audit = $this->makeAudit(auditNumber: 3, date: '2026-08-11');

        $this->assertNull($audit->audit_ref);
        $this->assertSame('AUD-11082026-0003', $audit->reference());
    }

    public function test_a_stored_reference_is_used_in_preference_to_a_derived_one(): void
    {
        $audit = $this->makeAudit(auditNumber: 3, date: '2026-08-11');
        $audit->update(['audit_ref' => 'AUD-09082026-0042']);

        // The handheld's own reference wins: it is what the operator saw.
        $this->assertSame('AUD-09082026-0042', $audit->fresh()->reference());
    }

    /**
     * The reason the index on (shop_id, audit_ref) is not unique.
     *
     * PharmaVerify numbers per shop *and device*; the handheld numbers per shop
     * only. Four devices counting one shop on one day therefore all derive the
     * same reference, and that is valid data rather than a clash to reject.
     */
    public function test_several_devices_at_one_shop_may_share_a_derived_reference(): void
    {
        $shop = $this->makeShop();

        foreach (['HHT-01', 'HHT-02', 'HHT-03'] as $code) {
            $device = $this->makeDevice($shop, $code);

            Audit::create([
                'shop_id' => $shop->id,
                'device_id' => $device->id,
                'audit_number' => 1,
                'audit_date' => '2026-08-11',
                'item_count' => 0,
                'status' => Audit::STATUS_SUBMITTED,
            ]);
        }

        $this->artisan('audits:backfill-references')->assertSuccessful();

        $refs = Audit::where('shop_id', $shop->id)->pluck('audit_ref');

        $this->assertCount(3, $refs);
        $this->assertSame(['AUD-11082026-0001'], $refs->unique()->values()->all());

        // And identity is still the triple, so all three audits survive.
        $this->assertSame(3, Audit::where('shop_id', $shop->id)->count());
    }

    public function test_the_backfill_is_idempotent(): void
    {
        $this->makeAudit(auditNumber: 5, date: '2026-08-11');

        $this->artisan('audits:backfill-references')->assertSuccessful();
        $first = Audit::first()->audit_ref;

        $this->artisan('audits:backfill-references')->assertSuccessful();
        $this->artisan('audits:backfill-references')->assertSuccessful();

        $this->assertSame($first, Audit::first()->audit_ref);
        $this->assertSame('AUD-11082026-0005', $first);
    }

    public function test_a_backfill_dry_run_writes_nothing(): void
    {
        $this->makeAudit(auditNumber: 5, date: '2026-08-11');

        $this->artisan('audits:backfill-references', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull(Audit::first()->audit_ref);
    }

    public function test_an_audit_records_how_it_arrived(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = $this->makeShop();
        $this->makeDevice($shop);
        $item = $this->makeItem();
        $this->makeStock($shop, $item, 40);

        $this->actingAs($user)->postJson('/api/hht/submissions', [
            'submission_uid' => 'SUB-SOURCE-1',
            'shop_code' => 'PHM001',
            'device_code' => 'HHT-01',
            'audit_number' => 1,
            'audit_date' => '2026-08-28',
            'items' => [['product_code' => 'MED-1001', 'physical_quantity' => 40, 'batch' => 'B001']],
        ])->assertCreated();

        $this->assertSame(Audit::SOURCE_API, Audit::firstOrFail()->source);
    }

    public function test_the_api_exposes_the_reference_for_an_audit_that_predates_it(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $audit = $this->makeAudit(auditNumber: 9, date: '2026-08-11');

        $response = $this->actingAs($user)->getJson('/api/audits/'.$audit->id)->assertOk();

        $this->assertSame('AUD-11082026-0009', $response->json('data.audit_ref'));
        $this->assertSame(9, $response->json('data.audit_number'));
    }

    // ------------------------------------------------------- stock takes

    public function test_a_shop_numbers_its_stock_takes_from_one_upwards(): void
    {
        $shop = $this->makeShop();

        $first = StockTakeSession::openFor($shop->id, null, '2026-08-17');
        $second = StockTakeSession::openFor($shop->id, null, '2026-08-17');

        $this->assertSame('STK-17082026-0001', $first->take_ref);
        $this->assertSame('STK-17082026-0002', $second->take_ref);
        $this->assertSame(1, $first->take_number);
        $this->assertSame(2, $second->take_number);
    }

    public function test_each_shop_runs_its_own_series(): void
    {
        $one = $this->makeShop('PHM001');
        $two = $this->makeShop('PHM002');

        $first = StockTakeSession::openFor($one->id, null, '2026-08-17');
        $other = StockTakeSession::openFor($two->id, null, '2026-08-17');

        // Both legitimately hold 0001 — the reference only means anything
        // alongside its shop.
        $this->assertSame('STK-17082026-0001', $first->take_ref);
        $this->assertSame('STK-17082026-0001', $other->take_ref);
    }

    /**
     * A number is evidence that a count happened. Withdrawing a cycle must not
     * hand its number to the next one, or two different sweeps become
     * indistinguishable in a report.
     */
    public function test_a_withdrawn_cycle_does_not_release_its_number(): void
    {
        $shop = $this->makeShop();

        $first = StockTakeSession::openFor($shop->id, null, '2026-08-17');
        $second = StockTakeSession::openFor($shop->id, null, '2026-08-17');

        $this->assertSame(2, $second->take_number);

        $second->delete();

        $third = StockTakeSession::openFor($shop->id, null, '2026-08-18');

        $this->assertSame(3, $third->take_number);
        $this->assertSame('STK-18082026-0003', $third->take_ref);

        // The withdrawn cycle is still there, just no longer current.
        $this->assertSame(1, StockTakeSession::onlyTrashed()->count());
        $this->assertSame(2, StockTakeSession::count());
        $this->assertNotNull($first->fresh());
    }

    public function test_opening_a_cycle_through_the_api_numbers_it_and_records_who(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = $this->makeShop();

        $response = $this->actingAs($user)->postJson('/api/stock-take-sessions', [
            'shop_id' => $shop->id,
            'take_date' => '2026-08-17',
        ])->assertCreated();

        $this->assertSame('STK-17082026-0001', $response->json('data.take_ref'));
        $this->assertSame(StockTakeSession::SOURCE_WEB, $response->json('data.source'));
        $this->assertSame($user->name, $response->json('data.created_by'));
    }

    public function test_a_counted_line_can_belong_to_a_cycle_and_carries_a_loose_figure(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = $this->makeShop();
        $session = StockTakeSession::openFor($shop->id, $user, '2026-08-17');

        $response = $this->actingAs($user)->postJson('/api/stock-takes', [
            'shop_id' => $shop->id,
            'stock_take_session_id' => $session->id,
            'description' => 'Rabeprazole 20mg Tablet',
            'physical_qty' => 12,
            'loose_qty' => 3,
            'batch' => 'B-777',
        ])->assertCreated();

        $this->assertEquals(12, $response->json('data.physical_qty'));
        $this->assertEquals(3, $response->json('data.loose_qty'));
        $this->assertSame($session->id, $response->json('data.stock_take_session_id'));

        // The cycle keeps its own tally in step.
        $this->assertSame(1, $session->fresh()->item_count);
    }

    /** Everything recorded before cycles existed keeps working untouched. */
    public function test_a_take_recorded_without_a_cycle_is_still_valid(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = $this->makeShop();

        $response = $this->actingAs($user)->postJson('/api/stock-takes', [
            'shop_id' => $shop->id,
            'description' => 'Loose blister found behind the counter',
            'physical_qty' => 4,
        ])->assertCreated();

        $this->assertNull($response->json('data.stock_take_session_id'));
        $this->assertEquals(0, $response->json('data.loose_qty'));

        $take = StockTake::firstOrFail();
        $this->assertNull($take->stock_take_session_id);
        $this->assertNull($take->session);
    }

    public function test_completing_a_cycle_records_the_time_and_the_tally(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = $this->makeShop();
        $session = StockTakeSession::openFor($shop->id, $user, '2026-08-17');

        $this->actingAs($user)->postJson('/api/stock-takes', [
            'shop_id' => $shop->id,
            'stock_take_session_id' => $session->id,
            'description' => 'One item',
            'physical_qty' => 1,
        ])->assertCreated();

        $response = $this->actingAs($user)
            ->postJson('/api/stock-take-sessions/'.$session->id.'/complete')
            ->assertOk();

        $this->assertSame(StockTakeSession::STATUS_COMPLETED, $response->json('data.status'));
        $this->assertSame(1, $response->json('data.item_count'));
        $this->assertNotNull($response->json('data.completed_at'));
    }

    public function test_a_cycle_reference_cannot_repeat_within_one_shop(): void
    {
        $shop = $this->makeShop();
        StockTakeSession::openFor($shop->id, null, '2026-08-17');

        $this->expectException(\Illuminate\Database\QueryException::class);

        StockTakeSession::create([
            'shop_id' => $shop->id,
            'take_ref' => 'STK-17082026-0001',
            'take_number' => 99,
            'take_date' => '2026-08-17',
        ]);
    }

    // ---------------------------------------------------------- helpers

    private function makeAudit(int $auditNumber, string $date): Audit
    {
        $shop = $this->makeShop();
        $device = $this->makeDevice($shop);

        return Audit::create([
            'shop_id' => $shop->id,
            'device_id' => $device->id,
            'audit_number' => $auditNumber,
            'audit_date' => Carbon::parse($date)->toDateString(),
            'item_count' => 0,
            'status' => Audit::STATUS_SUBMITTED,
        ]);
    }
}
