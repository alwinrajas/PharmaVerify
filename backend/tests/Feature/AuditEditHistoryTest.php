<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\AuditLine;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What survives when somebody corrects a counted line.
 *
 * The correction itself is straightforward; the reason these exist is the
 * record left behind. A counted figure that can be changed with no trace is a
 * figure nobody can defend afterwards, and "the count said 95" has to remain
 * answerable months later even after someone amended it to 97.
 *
 * The behaviour was already implemented and working. It had no test, which
 * meant nothing would have noticed if a refactor quietly dropped the logging.
 */
class AuditEditHistoryTest extends TestCase
{
    use RefreshDatabase;

    /** One counted line, ready to be corrected. */
    private function countedLine(float $system = 100, float $physical = 95): AuditLine
    {
        $shop = $this->makeShop();
        $device = $this->makeDevice($shop);
        $item = $this->makeItem();
        $stock = $this->makeStock($shop, $item, $system);

        $audit = Audit::create([
            'shop_id' => $shop->id,
            'device_id' => $device->id,
            'audit_number' => 1,
            'audit_date' => now()->toDateString(),
            'item_count' => 1,
            'status' => Audit::STATUS_SUBMITTED,
            'source' => Audit::SOURCE_API,
        ]);

        return AuditLine::create([
            'audit_id' => $audit->id,
            'shop_id' => $shop->id,
            'item_stock_id' => $stock->id,
            'product_code' => $stock->product_code,
            'barcode' => $stock->barcode,
            'description' => $stock->description,
            'system_qty' => $system,
            'physical_qty' => $physical,
            'loose_qty' => 0,
            'variance_qty' => AuditLine::calculateVariance($physical, 0, $system),
            'batch' => $stock->batch,
        ]);
    }

    private function latestVerificationLog(): ?object
    {
        return DB::table('activity_log')
            ->where('log_name', 'verification')
            ->latest('id')
            ->first();
    }

    public function test_correcting_a_counted_quantity_records_what_it_was_and_what_it_became(): void
    {
        $line = $this->countedLine(100, 95);
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $this->actingAs($user)
            ->patchJson('/api/verification/lines/'.$line->id, ['physical_qty' => 97])
            ->assertOk();

        $log = $this->latestVerificationLog();
        $this->assertNotNull($log, 'A correction with no record is a figure nobody can defend.');

        $properties = json_decode($log->properties, true);

        $this->assertSame('Audit line corrected during verification', $log->description);
        $this->assertEquals(95, $properties['changes']['physical_qty']['old']);
        $this->assertEquals(97, $properties['changes']['physical_qty']['new']);

        // Who, and against what. An entry naming neither is not an audit trail.
        $this->assertEquals($user->id, $log->causer_id);
        $this->assertEquals($line->id, $log->subject_id);
        $this->assertSame($line->product_code, $properties['product_code']);
        $this->assertNotNull($log->created_at);
    }

    public function test_the_variance_is_recomputed_rather_than_taken_from_the_request(): void
    {
        $line = $this->countedLine(100, 95);
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $this->actingAs($user)
            ->patchJson('/api/verification/lines/'.$line->id, [
                'physical_qty' => 97,
                // Offered by a client that has miscalculated, or an older one
                // using the opposite sign. Neither may reach the books.
                'variance_qty' => -999,
            ])
            ->assertOk();

        // 100 held, 97 counted: three short, and short is positive.
        $this->assertEquals(3, $line->fresh()->variance_qty);
    }

    public function test_several_corrections_each_leave_their_own_entry(): void
    {
        $line = $this->countedLine(100, 95);
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        foreach ([96, 97, 98] as $corrected) {
            $this->actingAs($user)
                ->patchJson('/api/verification/lines/'.$line->id, ['physical_qty' => $corrected])
                ->assertOk();
        }

        // The history is the sequence, not just the latest state: an amendment
        // walked back and forth is exactly the pattern an auditor looks for.
        $entries = DB::table('activity_log')
            ->where('log_name', 'verification')
            ->where('subject_id', $line->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $entries);

        $steps = $entries->map(function ($entry) {
            $change = json_decode($entry->properties, true)['changes']['physical_qty'];

            return [(float) $change['old'], (float) $change['new']];
        })->all();

        $this->assertSame([[95.0, 96.0], [96.0, 97.0], [97.0, 98.0]], $steps);
    }

    public function test_a_correction_that_changes_nothing_is_not_recorded_as_one(): void
    {
        $line = $this->countedLine(100, 95);
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $this->actingAs($user)
            ->patchJson('/api/verification/lines/'.$line->id, ['physical_qty' => 95])
            ->assertOk();

        // Verified, but not "corrected" — a history padded with entries that
        // changed nothing is one nobody reads.
        $this->assertSame('Audit line verified', $this->latestVerificationLog()?->description);
    }

    public function test_a_closed_audit_refuses_corrections(): void
    {
        $line = $this->countedLine(100, 95);
        $line->audit->update(['status' => Audit::STATUS_CLOSED]);

        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $this->actingAs($user)
            ->patchJson('/api/verification/lines/'.$line->id, ['physical_qty' => 97])
            ->assertStatus(422);

        $this->assertEquals(95, $line->fresh()->physical_qty);
    }

    public function test_a_user_without_the_edit_permission_cannot_correct_a_count(): void
    {
        $line = $this->countedLine(100, 95);
        $user = $this->userWithRole(Roles::SHOP_USER);

        $this->actingAs($user)
            ->patchJson('/api/verification/lines/'.$line->id, ['physical_qty' => 97])
            ->assertForbidden();

        $this->assertEquals(95, $line->fresh()->physical_qty);
        $this->assertNull($this->latestVerificationLog());
    }
}
