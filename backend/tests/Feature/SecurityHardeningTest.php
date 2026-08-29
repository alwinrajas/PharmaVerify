<?php

namespace Tests\Feature;

use App\Support\Roles;
use App\Support\TokenExpiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Response headers, cross-origin access and how long a token lives.
 *
 * None of this changes what the application does — it changes what a browser is
 * willing to do with the answer, and how long a stolen token stays useful. The
 * tests exist because all three are invisible in normal use: nothing looks
 * broken when a header is missing.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const SIGN_IN = '/api/auth/login';

    private function signIn(array $payload = []): TestResponse
    {
        $this->userWithRole(Roles::ADMINISTRATOR, ['email' => 'admin@pharmaverify.test']);

        return $this->postJson(self::SIGN_IN, array_merge([
            'email' => 'admin@pharmaverify.test',
            'password' => 'Pharma@2026',
        ], $payload));
    }

    // ------------------------------------------------------------- headers

    public function test_every_api_response_carries_the_baseline_headers(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_a_failed_request_is_protected_as_well_as_a_successful_one(): void
    {
        // A 401 is still a response a browser handles, so it needs the headers
        // just as much as a 200 does.
        $unauthorised = $this->getJson('/api/auth/me');
        $unauthorised->assertStatus(401);
        $unauthorised->assertHeader('X-Content-Type-Options', 'nosniff');

        $ok = $this->signIn();
        $ok->assertOk();
        $ok->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_api_forbids_every_content_source(): void
    {
        $csp = $this->getJson('/api/auth/me')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'none'", (string) $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $csp);
    }

    public function test_that_policy_is_not_imposed_on_pages_that_serve_markup(): void
    {
        // The API serves no markup, so it can refuse every source. A page that
        // does would be broken by the same policy, which is why it is scoped.
        $page = $this->get('/');

        $page->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertNull($page->headers->get('Content-Security-Policy'));
    }

    public function test_strict_transport_security_is_sent_only_over_https(): void
    {
        $this->assertNull(
            $this->getJson('/api/auth/me')->headers->get('Strict-Transport-Security'),
            'HSTS was sent over a plain connection, where it means nothing.'
        );

        config(['security.hsts.enabled' => true, 'security.hsts.max_age' => 31536000]);

        $secure = $this->getJson('https://localhost/api/auth/me');

        $this->assertStringContainsString(
            'max-age=31536000',
            (string) $secure->headers->get('Strict-Transport-Security')
        );
    }

    public function test_strict_transport_security_can_be_switched_off(): void
    {
        config(['security.hsts.enabled' => false]);

        $this->assertNull(
            $this->getJson('https://localhost/api/auth/me')->headers->get('Strict-Transport-Security')
        );
    }

    public function test_a_generated_workbook_still_downloads_and_is_not_sniffed(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $excel = $this->actingAs($user)->get('/api/reports/variance?format=xlsx');

        $excel->assertOk();
        $excel->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('spreadsheet', (string) $excel->headers->get('Content-Type'));
    }

    public function test_a_generated_pdf_still_downloads(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $pdf = $this->actingAs($user)->get('/api/reports/variance?format=pdf');

        $pdf->assertOk();
        $pdf->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('pdf', (string) $pdf->headers->get('Content-Type'));
    }

    // ---------------------------------------------------------------- CORS

    public function test_a_production_configuration_does_not_allow_a_developer_machine(): void
    {
        // The origins used to be hardcoded, so every deployment allowed them.
        config(['cors.allowed_origins' => ['https://pharmaverify.example.com']]);

        $response = $this->withHeaders(['Origin' => 'http://localhost:5173'])
            ->getJson('/api/auth/me');

        // With one configured origin the header carries that origin rather than
        // the one asking. The browser compares the two and refuses the response
        // on the mismatch, so what matters is that the requesting origin is
        // never the one authorised.
        $this->assertNotSame(
            'http://localhost:5173',
            $response->headers->get('Access-Control-Allow-Origin'),
            'A localhost origin was authorised against a production configuration.'
        );
    }

    public function test_the_configured_frontend_origin_is_allowed(): void
    {
        config(['cors.allowed_origins' => ['https://pharmaverify.example.com']]);

        $response = $this->withHeaders(['Origin' => 'https://pharmaverify.example.com'])
            ->getJson('/api/auth/me');

        $response->assertHeader('Access-Control-Allow-Origin', 'https://pharmaverify.example.com');
    }

    public function test_localhost_is_still_reachable_when_a_developer_configures_it(): void
    {
        // Development access is kept, but as an explicit setting rather than a
        // permanent default.
        config(['cors.allowed_origins' => ['http://localhost:5173']]);

        $this->withHeaders(['Origin' => 'http://localhost:5173'])
            ->getJson('/api/auth/me')
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }

    public function test_nothing_is_allowed_when_no_origin_is_configured(): void
    {
        config(['cors.allowed_origins' => []]);

        $this->assertNull(
            $this->withHeaders(['Origin' => 'https://anywhere.example.com'])
                ->getJson('/api/auth/me')
                ->headers->get('Access-Control-Allow-Origin')
        );
    }

    // -------------------------------------------------------- token expiry

    public function test_a_token_does_not_expire_until_a_window_is_configured(): void
    {
        // The shipped default. Changing it is an operational decision (D-09),
        // so the tests pin the behaviour rather than assume a value.
        config(['security.tokens.web_expiry_minutes' => null]);
        config(['security.tokens.device_expiry_minutes' => null]);

        $this->assertNull(TokenExpiry::for(TokenExpiry::WEB_TOKEN_NAME));
        $this->assertNull(TokenExpiry::for('HHT-02'));

        $this->signIn()->assertOk();

        $this->assertNull(DB::table('personal_access_tokens')->value('expires_at'));
    }

    public function test_the_web_and_device_windows_are_configured_separately(): void
    {
        config([
            'security.tokens.web_expiry_minutes' => 120,
            'security.tokens.device_expiry_minutes' => 10080,
        ]);

        $this->assertSame(120, TokenExpiry::configuredMinutes(TokenExpiry::WEB_TOKEN_NAME));
        $this->assertSame(10080, TokenExpiry::configuredMinutes('HHT-02'));

        // A browser window must not be applied to a handheld terminal.
        $this->assertNotEquals(
            TokenExpiry::configuredMinutes(TokenExpiry::WEB_TOKEN_NAME),
            TokenExpiry::configuredMinutes('HHT-02')
        );
    }

    public function test_a_configured_window_reaches_the_issued_token(): void
    {
        config(['security.tokens.web_expiry_minutes' => 120]);

        $this->signIn()->assertOk();

        $expiresAt = DB::table('personal_access_tokens')->value('expires_at');

        $this->assertNotNull($expiresAt, 'A window was configured but the issued token has no expiry.');
        $this->assertEqualsWithDelta(
            now()->addMinutes(120)->timestamp,
            Carbon::parse($expiresAt)->timestamp,
            60
        );
    }

    public function test_a_device_signing_in_gets_the_device_window_not_the_web_one(): void
    {
        config([
            'security.tokens.web_expiry_minutes' => 60,
            'security.tokens.device_expiry_minutes' => null,
        ]);

        $this->signIn(['device_name' => 'HHT-02'])->assertOk();

        $this->assertNull(
            DB::table('personal_access_tokens')->where('name', 'HHT-02')->value('expires_at'),
            'A handheld terminal was given an expiry that was only configured for the web.'
        );
    }

    public function test_an_empty_or_zero_setting_means_no_expiry_rather_than_immediate(): void
    {
        // The difference between an unset variable and a working system.
        foreach (['', 0, '0'] as $value) {
            config(['security.tokens.web_expiry_minutes' => $value]);

            $this->assertNull(
                TokenExpiry::configuredMinutes(TokenExpiry::WEB_TOKEN_NAME),
                'A value of '.var_export($value, true).' was read as an immediate expiry.'
            );
        }
    }

    // ------------------------------------------------ nothing else regressed

    public function test_signing_in_still_works_and_returns_a_usable_token(): void
    {
        $response = $this->signIn();

        $response->assertOk();
        $token = $response->json('data.token');
        $this->assertNotEmpty($token);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_rate_limiting_still_refuses_repeated_failures(): void
    {
        $this->userWithRole(Roles::ADMINISTRATOR, ['email' => 'admin@pharmaverify.test']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson(self::SIGN_IN, [
                'email' => 'admin@pharmaverify.test',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson(self::SIGN_IN, [
            'email' => 'admin@pharmaverify.test',
            'password' => 'wrong-password',
        ])->assertStatus(429)->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    // ------------------------------------------------------------- leakage

    public function test_no_credential_or_token_appears_in_a_response_header(): void
    {
        $response = $this->signIn();
        $token = (string) $response->json('data.token');

        foreach ($response->headers->all() as $name => $values) {
            foreach ($values as $value) {
                $this->assertStringNotContainsString($token, (string) $value, "The token leaked into the {$name} header.");
                $this->assertStringNotContainsString('Pharma@2026', (string) $value, "The password leaked into the {$name} header.");
            }
        }
    }

    public function test_an_expired_token_is_refused(): void
    {
        config(['security.tokens.web_expiry_minutes' => 60]);

        $token = $this->signIn()->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/auth/me')->assertOk();

        // Sanctum reads expires_at on every request, so moving past it is enough.
        $this->travel(61)->minutes();

        // The guard resolved a user on the request above and holds it for the
        // rest of this test, which would mask the expiry rather than prove it.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }
}
