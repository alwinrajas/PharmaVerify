<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\AuditLine;
use App\Models\Device;
use App\Models\HhtSubmission;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The contract between a device submission and what the office then sees.
 *
 * HhtSubmissionTest covers the count arriving. This covers the half after it:
 * that the audit the web reads back says where it came from, carries the
 * reference the operator was shown, and holds figures the server worked out
 * rather than ones the handheld asserted.
 */
class HhtReceivedInWebTest extends TestCase
{
    use RefreshDatabase;

    /** A completed count, ready to post. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'submission_uid' => 'SUB-RECEIVED-1',
            'shop_code' => 'PHM001',
            'device_code' => 'HHT-01',
            'audit_number' => 41,
            'audit_date' => '2026-08-28',
            'hht_user' => 'Karthik Subramani',
            'items' => [
                ['product_code' => 'MED-1001', 'physical_quantity' => 6, 'loose_quantity' => 2, 'batch' => 'B001'],
            ],
        ], $overrides);
    }

    public function test_an_api_submission_is_recorded_as_having_come_from_a_device(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = $this->makeShop();
        $this->makeDevice($shop);
        $item = $this->makeItem();
        $this->makeStock($shop, $item, 10);

        $this->actingAs($user)->postJson('/api/hht/submissions', $this->payload())->assertCreated();

        // The web tells a direct submission from an imported spreadsheet by
        // this field alone. Excel imports set 'excel'; if this path ever stops
        // saying 'api', every audit reads as an import.
        $this->assertSame(Audit::SOURCE_API, Audit::firstOrFail()->source);
    }

    public function test_the_audit_the_web_reads_carries_the_reference_the_device_was_given(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = $this->makeShop();
        $this->makeDevice($shop);
        $item = $this->makeItem();
        $this->makeStock($shop, $item, 10);

        $submitted = $this->actingAs($user)
            ->postJson('/api/hht/submissions', $this->payload())
            ->assertCreated();

        $reference = $submitted->json('data.audit_ref');
        $this->assertNotEmpty($reference);

        // What the operator reads off the handheld has to be what they can
        // quote to the office, so the same string must come back from the
        // audit endpoint the web reads.
        $audit = Audit::firstOrFail();
        $this->actingAs($user)
            ->getJson("/api/audits/{$audit->id}")
            ->assertOk()
            ->assertJsonPath('data.audit_ref', $reference)
            ->assertJsonPath('data.source', Audit::SOURCE_API)
            ->assertJsonPath('data.device_code', 'HHT-01');
    }

    public function test_a_retry_after_a_lost_reply_returns_the_original_reference(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = $this->makeShop();
        $this->makeDevice($shop);
        $item = $this->makeItem();
        $this->makeStock($shop, $item, 10);

        $first = $this->actingAs($user)->postJson('/api/hht/submissions', $this->payload())->assertCreated();
        $retry = $this->actingAs($user)->postJson('/api/hht/submissions', $this->payload());

        $retry->assertJsonPath('data.status', HhtSubmission::STATUS_DUPLICATE);

        // The device shows whatever comes back. A second reference here would
        // put two numbers for one count into circulation.
        $this->assertSame($first->json('data.audit_ref'), $retry->json('data.audit_ref'));
        $this->assertSame(1, Audit::count());
    }

    public function test_the_server_works_out_variance_and_ignores_what_the_device_claims(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = $this->makeShop();
        $this->makeDevice($shop);
        $item = $this->makeItem();
        $this->makeStock($shop, $item, 10);

        $this->actingAs($user)->postJson('/api/hht/submissions', $this->payload([
            'items' => [[
                'product_code' => 'MED-1001',
                'physical_quantity' => 6,
                'loose_quantity' => 2,
                'batch' => 'B001',
                // A device that has miscounted, or an older one using the
                // opposite sign. Neither may reach the books.
                'variance_quantity' => -999,
            ]],
        ]))->assertCreated();

        $line = AuditLine::firstOrFail();

        // 10 held, 6 whole and 2 loose found: two short, and short is positive.
        $this->assertEquals(10, $line->system_qty);
        $this->assertEquals(6, $line->physical_qty);
        $this->assertEquals(2, $line->loose_qty);
        $this->assertEquals(2, $line->variance_qty);
    }

    public function test_nothing_is_left_behind_when_a_submission_fails_partway(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = $this->makeShop();
        $this->makeDevice($shop);
        $item = $this->makeItem();
        $this->makeStock($shop, $item, 10);

        // Fail after the audit row exists but before its lines are finished.
        // A half-written audit would appear in the web as a real count with
        // items missing, which is worse than no audit at all.
        DB::listen(function ($query) {
            if (str_contains(strtolower($query->sql), 'insert into "audit_lines"')
                || str_contains(strtolower($query->sql), 'insert into `audit_lines`')) {
                throw new \RuntimeException('storage failed mid-write');
            }
        });

        try {
            $this->actingAs($user)->postJson('/api/hht/submissions', $this->payload());
        } catch (\Throwable) {
            // The response does not matter here; what is on disk does.
        }

        $this->assertSame(0, Audit::count());
        $this->assertSame(0, AuditLine::count());
    }

    public function test_a_device_paired_to_one_shop_cannot_file_against_another(): void
    {
        $ownShop = $this->makeShop();
        $device = $this->makeDevice($ownShop);

        // Another shop that happens to run a terminal of the same code, which
        // is ordinary: device codes are per shop, not globally unique.
        $otherShop = $this->makeShop('T033');
        $this->makeDevice($otherShop);

        $token = $device->createToken('pairing-test', [Device::ABILITY_SUBMIT])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/hht/submissions', $this->payload([
                'submission_uid' => 'SUB-CROSS-SHOP',
                'shop_code' => 'T033',
                'audit_number' => 91,
            ]));

        // The payload decides which books the count lands in, so a terminal
        // saying it belongs somewhere else must not be taken at its word. Its
        // identity is the token, which it cannot choose.
        $response->assertForbidden();
        $this->assertSame(0, Audit::count());
    }

    public function test_a_device_cannot_file_under_another_terminals_code(): void
    {
        $shop = $this->makeShop();
        $device = $this->makeDevice($shop);
        $this->makeDevice($shop, 'HHT-09');

        $token = $device->createToken('pairing-test', [Device::ABILITY_SUBMIT])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/hht/submissions', $this->payload([
                'submission_uid' => 'SUB-CROSS-DEVICE',
                'device_code' => 'HHT-09',
                'audit_number' => 92,
            ]))
            ->assertForbidden();

        $this->assertSame(0, Audit::count());
    }

    public function test_a_wrong_shop_refusal_is_marked_so_the_device_can_tell_it_apart(): void
    {
        $ownShop = $this->makeShop();
        $device = $this->makeDevice($ownShop);
        $this->makeShop('T033');

        $token = $device->createToken('pairing-test', [Device::ABILITY_SUBMIT])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/hht/submissions', $this->payload([
                'submission_uid' => 'SUB-CODED-MISMATCH',
                'shop_code' => 'T033',
                'audit_number' => 93,
            ]));

        // A handheld cannot read English prose. Without a code it sees only
        // "403", which it reported to operators as a revoked pairing — sending
        // them to re-pair a terminal whose pairing was perfectly good.
        $response->assertForbidden()
            ->assertJsonPath('code', 'shop_mismatch')
            ->assertJsonPath('paired_shop_code', $ownShop->shop_code)
            ->assertJsonPath('attempted_shop_code', 'T033');
    }

    public function test_a_device_filing_its_own_count_is_accepted(): void
    {
        $shop = $this->makeShop();
        $device = $this->makeDevice($shop);
        $item = $this->makeItem();
        $this->makeStock($shop, $item, 10);

        $token = $device->createToken('pairing-test', [Device::ABILITY_SUBMIT])->plainTextToken;

        // The guard must refuse impostors without getting in the way of the
        // ordinary case it exists to protect.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/hht/submissions', $this->payload(['submission_uid' => 'SUB-OWN-SHOP']))
            ->assertCreated();

        $this->assertSame(1, Audit::count());
    }
}
