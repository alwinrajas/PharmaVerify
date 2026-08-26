<?php

namespace Tests\Feature;

use App\Support\Roles;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Rate limiting.
 *
 * Laravel does not throttle the API group on its own, so without this every
 * endpoint — sign-in included — accepted unlimited attempts. These tests cover
 * the two things that matter: that a caller going too fast is refused, and that
 * being refused never affects anybody else.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Each test starts with a clean allowance; counts would otherwise carry
        // between tests through the shared cache store.
        cache()->clear();
    }

    public function test_repeated_failed_sign_in_attempts_are_eventually_refused(): void
    {
        $this->userWithRole(Roles::ADMINISTRATOR, ['email' => 'target@pharmaverify.test']);

        // The limit is five a minute for one address and email together.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => 'target@pharmaverify.test',
                'password' => 'wrong-password',
            ])->assertStatus(422, "attempt {$attempt} should still be answered normally");
        }

        $this->postJson('/api/auth/login', [
            'email' => 'target@pharmaverify.test',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_the_correct_password_is_refused_too_once_the_limit_is_reached(): void
    {
        $this->userWithRole(Roles::ADMINISTRATOR, ['email' => 'target@pharmaverify.test']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => 'target@pharmaverify.test',
                'password' => 'wrong-password',
            ]);
        }

        // Guessing must not be rewarded by eventually landing on the right one.
        $this->postJson('/api/auth/login', [
            'email' => 'target@pharmaverify.test',
            'password' => 'Pharma@2026',
        ])->assertStatus(429);
    }

    public function test_signing_in_works_normally_within_the_limit(): void
    {
        $this->userWithRole(Roles::ADMINISTRATOR, ['email' => 'ok@pharmaverify.test']);

        // A couple of fumbles, then the right password, all inside the allowance.
        foreach (range(1, 3) as $ignored) {
            $this->postJson('/api/auth/login', [
                'email' => 'ok@pharmaverify.test',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $response = $this->postJson('/api/auth/login', [
            'email' => 'ok@pharmaverify.test',
            'password' => 'Pharma@2026',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_one_accounts_failures_do_not_lock_another_account_out(): void
    {
        $this->userWithRole(Roles::ADMINISTRATOR, ['email' => 'noisy@pharmaverify.test']);
        $this->userWithRole(Roles::SUPERVISOR, ['email' => 'quiet@pharmaverify.test']);

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => 'noisy@pharmaverify.test',
                'password' => 'wrong-password',
            ]);
        }

        $this->postJson('/api/auth/login', [
            'email' => 'noisy@pharmaverify.test',
            'password' => 'wrong-password',
        ])->assertStatus(429);

        // The other account is untouched: the limit is per email and address,
        // not one shared counter.
        $other = $this->postJson('/api/auth/login', [
            'email' => 'quiet@pharmaverify.test',
            'password' => 'Pharma@2026',
        ]);

        $other->assertOk();
        $this->assertNotEmpty($other->json('data.token'));
    }

    public function test_a_throttled_response_uses_the_standard_error_envelope(): void
    {
        $this->userWithRole(Roles::ADMINISTRATOR, ['email' => 'target@pharmaverify.test']);

        $response = null;

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => 'target@pharmaverify.test',
                'password' => 'wrong-password',
            ]);
        }

        $response->assertStatus(429);

        $body = $response->json();

        $this->assertFalse($body['success'], 'The envelope reports failure like every other error.');
        $this->assertSame('Too many requests. Please wait a moment and try again.', $body['message']);
        $this->assertIsInt($body['retry_after_seconds']);
        $this->assertGreaterThan(0, $body['retry_after_seconds']);

        // The client is told how long to wait through the standard header too.
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function test_a_throttled_response_leaks_nothing_technical(): void
    {
        $this->userWithRole(Roles::ADMINISTRATOR, ['email' => 'target@pharmaverify.test']);

        $response = null;

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => 'target@pharmaverify.test',
                'password' => 'wrong-password',
            ]);
        }

        $response->assertStatus(429);

        $body = $response->json();

        foreach (['exception', 'trace', 'file', 'line', 'debug'] as $key) {
            $this->assertArrayNotHasKey($key, $body, "A throttled reply must not carry '{$key}'.");
        }

        $raw = $response->getContent();

        $this->assertStringNotContainsString('Illuminate\\', $raw);
        $this->assertStringNotContainsString('vendor/', $raw);
        $this->assertStringNotContainsString('Too Many Attempts', $raw, "Laravel's own wording should not surface.");

        // The reply must not echo the account that was being attempted.
        $this->assertStringNotContainsString('target@pharmaverify.test', $raw);
    }

    public function test_the_limit_does_not_reveal_whether_an_account_exists(): void
    {
        $this->seedRoles();

        // No such user. The refusal must read the same as for a real one.
        $response = null;

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => 'nobody@pharmaverify.test',
                'password' => 'wrong-password',
            ]);
        }

        $response->assertStatus(429);
        $this->assertSame('Too many requests. Please wait a moment and try again.', $response->json('message'));
    }

    public function test_authenticated_traffic_is_throttled_per_user_not_globally(): void
    {
        $busy = $this->userWithRole(Roles::ADMINISTRATOR);
        $other = $this->userWithRole(Roles::SUPERVISOR);

        // A deliberately small allowance, keyed the same way the real one is,
        // so the isolation can be shown without issuing 300 requests.
        RateLimiter::for('api', fn ($request) => $request->user()
            ? Limit::perMinute(3)->by('api-user:'.$request->user()->id)
            : Limit::perMinute(60)->by('api-ip:'.$request->ip()));

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($busy)->getJson('/api/shops')->assertOk();
        }

        $this->actingAs($busy)->getJson('/api/shops')->assertStatus(429);

        // A different user is unaffected, because the key carries the user id.
        $this->actingAs($other)->getJson('/api/shops')->assertOk();
    }

    public function test_an_hht_device_can_retry_a_submission(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = $this->makeShop();
        $this->makeDevice($shop);
        $item = $this->makeItem();
        $this->makeStock($shop, $item, 100);

        $payload = [
            'submission_uid' => 'SUB-RETRY-RATE',
            'shop_code' => 'PHM001',
            'device_code' => 'HHT-01',
            'audit_number' => 9,
            'audit_date' => '2026-08-26',
            'items' => [['product_code' => 'MED-1001', 'physical_quantity' => 95, 'batch' => 'B001']],
        ];

        $first = $this->actingAs($user)->postJson('/api/hht/submissions', $payload);
        $first->assertCreated();

        // A device that lost its connection retries the same count repeatedly.
        // Every retry must still be answered so idempotency can do its job —
        // throttling here would strand a finished count on the device.
        foreach (range(1, 8) as $attempt) {
            $retry = $this->actingAs($user)->postJson('/api/hht/submissions', $payload);

            $retry->assertOk();
            $this->assertSame('duplicate_ignored', $retry->json('data.status'), "retry {$attempt}");
            $this->assertSame($first->json('data.audit_id'), $retry->json('data.audit_id'));
        }
    }

    public function test_a_stock_import_is_usable_within_the_legitimate_threshold(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = $this->makeShop();

        // The allowance is six in ten minutes. A run of genuine imports must go
        // through; only the seventh is refused.
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $response = $this->actingAs($user)->post('/api/stock-imports', [
                'shop_id' => $shop->id,
                'file' => $this->flatStockFile(),
            ], ['Accept' => 'application/json']);

            $this->assertNotSame(429, $response->getStatusCode(), "import {$attempt} should not be throttled");
            $response->assertCreated();
        }

        $this->actingAs($user)->post('/api/stock-imports', [
            'shop_id' => $shop->id,
            'file' => $this->flatStockFile(),
        ], ['Accept' => 'application/json'])->assertStatus(429);
    }

    /** A minimal valid flat stock file, so the import itself is never the reason a call fails. */
    private function flatStockFile(): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach (['Product Code', 'Product Description', 'System Stock'] as $column => $heading) {
            $sheet->setCellValue([$column + 1, 1], $heading);
        }

        foreach (['MED-1001', 'Paracetamol 500mg Tablet', 40] as $column => $value) {
            $sheet->setCellValue([$column + 1, 2], $value);
        }

        $path = tempnam(sys_get_temp_dir(), 'pv-rate-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'stock.xlsx', null, null, true);
    }
}
