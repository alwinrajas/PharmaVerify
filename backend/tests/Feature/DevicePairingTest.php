<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\AuditLine;
use App\Models\Device;
use App\Models\Shop;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * A handheld authenticating as itself.
 *
 * The point of pairing is that a terminal stops borrowing a person's token. A
 * count then belongs to the device that took it, and an operator ending their
 * shift does not end the terminal's ability to report in.
 *
 * The pairing endpoint is necessarily unauthenticated — a device that has never
 * paired has nothing to authenticate with — so most of what follows is about
 * what that endpoint refuses.
 */
class DevicePairingTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_administrator_issues_a_code_and_the_terminal_exchanges_it_for_a_token(): void
    {
        $admin = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = $this->makeShop();
        $device = $this->makeDevice($shop, 'HHT-01');

        $issued = $this->actingAs($admin)
            ->postJson('/api/devices/'.$device->id.'/pairing-code')
            ->assertOk();

        $code = $issued->json('data.pairing_code');
        $this->assertNotEmpty($code);

        // Stored hashed, never in the clear.
        $device->refresh();
        $this->assertNotSame($code, $device->pairing_code_hash);
        $this->assertTrue(Hash::check($code, $device->pairing_code_hash));

        $paired = $this->postJson('/api/hht/devices/pair', [
            'device_code' => 'HHT-01',
            'pairing_code' => $code,
            'serial_number' => 'SN-9001',
        ])->assertCreated();

        $this->assertNotEmpty($paired->json('data.token'));
        $this->assertSame('HHT-01', $paired->json('data.device.device_code'));
        $this->assertSame($shop->shop_code, $paired->json('data.device.shop_code'));

        $device->refresh();
        $this->assertNotNull($device->paired_at);
        $this->assertSame('SN-9001', $device->serial_number);
        // Spent: the code is gone whatever happens next.
        $this->assertNull($device->pairing_code_hash);
    }

    public function test_a_pairing_code_works_only_once(): void
    {
        [$device, $code] = $this->issuedCode();

        $this->postJson('/api/hht/devices/pair', ['device_code' => $device->device_code, 'pairing_code' => $code])
            ->assertCreated();

        $this->postJson('/api/hht/devices/pair', ['device_code' => $device->device_code, 'pairing_code' => $code])
            ->assertStatus(422);

        $this->assertSame(1, PersonalAccessToken::count());
    }

    public function test_an_expired_code_is_refused(): void
    {
        [$device, $code] = $this->issuedCode();

        $device->forceFill(['pairing_expires_at' => now()->subMinute()])->save();

        $this->postJson('/api/hht/devices/pair', ['device_code' => $device->device_code, 'pairing_code' => $code])
            ->assertStatus(422);

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_a_wrong_code_is_refused_and_says_nothing_useful(): void
    {
        [$device] = $this->issuedCode();

        $wrong = $this->postJson('/api/hht/devices/pair', [
            'device_code' => $device->device_code,
            'pairing_code' => 'WRONGCODE',
        ])->assertStatus(422);

        $unknown = $this->postJson('/api/hht/devices/pair', [
            'device_code' => 'NO-SUCH-DEVICE',
            'pairing_code' => 'WRONGCODE',
        ])->assertStatus(422);

        // The same answer either way: a caller cannot use this to discover
        // which device codes exist.
        $this->assertSame($wrong->json('message'), $unknown->json('message'));
    }

    public function test_pairing_again_revokes_the_previous_token(): void
    {
        [$device, $first] = $this->issuedCode();

        $one = $this->postJson('/api/hht/devices/pair', [
            'device_code' => $device->device_code, 'pairing_code' => $first,
        ])->assertCreated()->json('data.token');

        $second = $this->reissue($device);

        $two = $this->postJson('/api/hht/devices/pair', [
            'device_code' => $device->device_code, 'pairing_code' => $second,
        ])->assertCreated()->json('data.token');

        $this->assertNotSame($one, $two);
        $this->assertSame(1, PersonalAccessToken::count(), 'A re-paired terminal leaves no working token behind it.');

        // The old one is dead — which is the point of re-pairing a lost device.
        $this->withHeader('Authorization', 'Bearer '.$one)
            ->getJson('/api/hht/devices/me')
            ->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer '.$two)
            ->getJson('/api/hht/devices/me')
            ->assertOk();
    }

    public function test_an_inactive_device_cannot_pair(): void
    {
        [$device, $code] = $this->issuedCode();
        $device->forceFill(['status' => 'inactive'])->save();

        $this->postJson('/api/hht/devices/pair', ['device_code' => $device->device_code, 'pairing_code' => $code])
            ->assertStatus(422);
    }

    public function test_only_a_user_who_may_edit_devices_can_issue_a_code(): void
    {
        $shopUser = $this->userWithRole(Roles::SHOP_USER);
        $shop = $this->makeShop();
        $device = $this->makeDevice($shop);

        $this->actingAs($shopUser)
            ->postJson('/api/devices/'.$device->id.'/pairing-code')
            ->assertForbidden();

        $this->assertNull($device->fresh()->pairing_code_hash);
    }

    // --------------------------------------------------------- the token

    public function test_a_paired_device_identifies_itself(): void
    {
        $token = $this->pairedToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/hht/devices/me')
            ->assertOk();

        $this->assertSame('HHT-01', $response->json('data.device_code'));
        $this->assertSame('PHM001', $response->json('data.shop_code'));
        $this->assertNotNull($response->json('data.server_time'));

        // The call is also a heartbeat.
        $this->assertNotNull(Device::firstOrFail()->last_seen_at);
    }

    /** The whole point: a device token reaches the submission endpoint. */
    public function test_a_device_submits_a_count_with_its_own_token(): void
    {
        $token = $this->pairedToken();
        $item = $this->makeItem();
        $this->makeStock(Shop::firstOrFail(), $item, 100);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/hht/submissions', [
                'submission_uid' => 'P001-AUD-28082026-0009',
                'shop_code' => 'PHM001',
                'device_code' => 'HHT-01',
                'audit_number' => 9,
                'audit_date' => '2026-08-28',
                'hht_user' => 'Karthik Subramani',
                'app_version' => '1.0.0',
                'items' => [
                    ['product_code' => 'MED-1001', 'batch' => 'B001', 'physical_quantity' => 95, 'loose_quantity' => 3],
                ],
            ])->assertCreated();

        $this->assertSame('accepted', $response->json('data.status'));

        $line = AuditLine::firstOrFail();
        $this->assertEquals(95, $line->physical_qty);
        $this->assertEquals(3, $line->loose_qty);
        // 100 - (95 + 3) = 2 short, and short is positive.
        $this->assertEquals(2, $line->variance_qty);
    }

    /** Re-sending after a dropped connection must not make a second audit. */
    public function test_a_device_retrying_the_same_submission_gets_the_same_audit(): void
    {
        $token = $this->pairedToken();
        $item = $this->makeItem();
        $this->makeStock(Shop::firstOrFail(), $item, 100);

        $payload = [
            'submission_uid' => 'P001-AUD-28082026-0010',
            'shop_code' => 'PHM001',
            'device_code' => 'HHT-01',
            'audit_number' => 10,
            'audit_date' => '2026-08-28',
            'items' => [['product_code' => 'MED-1001', 'batch' => 'B001', 'physical_quantity' => 95]],
        ];

        $first = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/hht/submissions', $payload)->assertCreated();

        $second = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/hht/submissions', $payload)->assertOk();

        $this->assertSame('duplicate_ignored', $second->json('data.status'));
        $this->assertSame($first->json('data.audit_id'), $second->json('data.audit_id'));
        $this->assertSame(1, Audit::count());
        $this->assertSame(1, AuditLine::count());
    }

    public function test_an_unpaired_device_cannot_submit(): void
    {
        $this->postJson('/api/hht/submissions', [
            'shop_code' => 'PHM001', 'device_code' => 'HHT-01',
            'audit_number' => 1, 'audit_date' => '2026-08-28',
            'items' => [['product_code' => 'MED-1001', 'physical_quantity' => 1]],
        ])->assertUnauthorized();

        $this->assertSame(0, Audit::count());
    }

    public function test_revoking_a_pairing_stops_the_terminal_submitting(): void
    {
        $admin = $this->userWithRole(Roles::ADMINISTRATOR);
        $token = $this->pairedToken();
        $device = Device::firstOrFail();

        $this->actingAs($admin)
            ->deleteJson('/api/devices/'.$device->id.'/pairing')
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/hht/devices/me')
            ->assertUnauthorized();

        $this->assertNull($device->fresh()->paired_at);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    // ---------------------------------------------------------- helpers

    /** @return array{0: Device, 1: string} */
    private function issuedCode(): array
    {
        $admin = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = Shop::first() ?? $this->makeShop();
        $device = Device::first() ?? $this->makeDevice($shop, 'HHT-01');

        $code = $this->actingAs($admin)
            ->postJson('/api/devices/'.$device->id.'/pairing-code')
            ->assertOk()
            ->json('data.pairing_code');

        // actingAs leaves the admin authenticated for the rest of the test.
        // Every request after this one is the terminal's, carrying only its
        // bearer token, so the session has to go.
        $this->app['auth']->forgetGuards();

        return [$device->fresh(), $code];
    }

    private function reissue(Device $device): string
    {
        $admin = $this->userWithRole(Roles::ADMINISTRATOR);

        $code = $this->actingAs($admin)
            ->postJson('/api/devices/'.$device->id.'/pairing-code')
            ->assertOk()
            ->json('data.pairing_code');

        $this->app['auth']->forgetGuards();

        return $code;
    }

    private function pairedToken(): string
    {
        [$device, $code] = $this->issuedCode();

        return $this->postJson('/api/hht/devices/pair', [
            'device_code' => $device->device_code,
            'pairing_code' => $code,
        ])->assertCreated()->json('data.token');
    }
}
