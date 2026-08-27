<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\AuditLine;
use App\Models\FinalOutput;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sharing a final output to OneDrive through Microsoft Graph.
 *
 * Microsoft Graph is faked at the network boundary — no real call is ever made,
 * and no real credential is needed. What is being tested is our half of the
 * conversation: that we refuse to start without complete configuration, that a
 * failure is reported in words an administrator can act on, and above all that
 * nothing uploads unless a user asked for it.
 */
class OneDriveShareTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-2222-3333-4444-555555555555';

    private const CLIENT = '66666666-7777-8888-9999-000000000000';

    /** A value that must never appear in a response, a log line or the database. */
    private const SECRET = 'test-secret-DO-NOT-LEAK-9f3a2b';

    private const TOKEN = 'test-access-token-DO-NOT-LEAK-4c8e1d';

    private int $fixtureCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Cache::flush();
    }

    // ---------------------------------------------------------------- helpers

    /** Point the application at the Graph driver with complete configuration. */
    private function useGraph(array $overrides = []): void
    {
        config(array_merge([
            'onedrive.driver' => 'graph',
            'onedrive.tenant_id' => self::TENANT,
            'onedrive.client_id' => self::CLIENT,
            'onedrive.client_secret' => self::SECRET,
            'onedrive.drive_id' => 'b!test-drive-id',
            'onedrive.user_principal' => null,
            'onedrive.folder' => 'PharmaVerify/FinalOutput',
        ], $overrides));
    }

    /**
     * A generated final output with a real file behind it, ready to share.
     */
    private function makeFinalOutput(User $user, int $bytes = 2048): FinalOutput
    {
        // Each call builds its own shop and product so a test can share more
        // than one output without colliding on the unique codes.
        $n = ++$this->fixtureCounter;

        $shop = $this->makeShop(sprintf('PHM%03d', $n));
        $device = $this->makeDevice($shop);
        $item = $this->makeItem(sprintf('MED-%04d', 1000 + $n), sprintf('890123450%04d', $n));
        $stock = $this->makeStock($shop, $item);

        $audit = Audit::create([
            'shop_id' => $shop->id,
            'device_id' => $device->id,
            'audit_number' => 1,
            'audit_date' => '2026-08-26',
            'submitted_at' => now(),
            'item_count' => 1,
            'status' => Audit::STATUS_VERIFIED,
        ]);

        AuditLine::create([
            'audit_id' => $audit->id,
            'shop_id' => $shop->id,
            'item_stock_id' => $stock->id,
            'product_code' => $item->product_code,
            'description' => $item->description,
            'system_qty' => 100,
            'physical_qty' => 100,
            'variance_qty' => 0,
            'verification_status' => 'verified',
        ]);

        $path = sprintf('final-output/%s_HHT-01_Audit-1.xlsx', $shop->shop_code);
        Storage::disk('local')->put($path, str_repeat('x', $bytes));

        return FinalOutput::create([
            'shop_id' => $shop->id,
            'audit_id' => $audit->id,
            'file_name' => sprintf('%s_HHT-01_Audit-1.xlsx', $shop->shop_code),
            'file_path' => $path,
            'record_count' => 1,
            'verification_status' => 'verified',
            'adjustment_status' => 'none',
            'onedrive_status' => FinalOutput::ONEDRIVE_NOT_UPLOADED,
            'upload_attempts' => 0,
            'generated_by' => $user->id,
            'generated_at' => now(),
        ]);
    }

    private function share(User $user, FinalOutput $output)
    {
        return $this->actingAs($user)->postJson("/api/final-outputs/{$output->id}/share-onedrive");
    }

    /** A successful token response from Azure AD. */
    private function tokenOk(): array
    {
        return ['access_token' => self::TOKEN, 'expires_in' => 3600, 'token_type' => 'Bearer'];
    }

    // ------------------------------------------------- 1. configuration gaps

    public function test_an_unconfigured_graph_driver_refuses_before_any_network_call(): void
    {
        Http::fake();
        $this->useGraph(['onedrive.client_id' => null, 'onedrive.client_secret' => null]);

        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $output = $this->makeFinalOutput($user);

        $response = $this->share($user, $output);

        $response->assertStatus(502);
        $this->assertStringContainsString('not been configured', $response->json('message'));

        // The point of the guard: nothing should reach Microsoft at all.
        Http::assertNothingSent();

        $this->assertSame(FinalOutput::ONEDRIVE_FAILED, $output->fresh()->onedrive_status);
    }

    /**
     * The shipped .env.example carries YOUR_TENANT_ID / YOUR_CLIENT_SECRET
     * placeholders. Filling in only some of them must not count as configured:
     * a half-filled configuration should be reported as a configuration
     * problem, not sent to Microsoft and reported as a sign-in failure.
     */
    public function test_a_placeholder_left_in_any_credential_counts_as_unconfigured(): void
    {
        foreach ([
            'onedrive.tenant_id' => 'YOUR_TENANT_ID',
            'onedrive.client_id' => 'YOUR_CLIENT_ID',
            'onedrive.client_secret' => 'YOUR_CLIENT_SECRET',
        ] as $key => $placeholder) {
            Http::fake();
            $this->useGraph([$key => $placeholder]);

            $user = $this->userWithRole(Roles::ADMINISTRATOR);
            $output = $this->makeFinalOutput($user);

            $response = $this->share($user, $output);

            $this->assertStringContainsString(
                'not been configured',
                (string) $response->json('message'),
                "A placeholder left in {$key} was treated as real configuration."
            );

            Http::assertNothingSent();

            // Reset for the next iteration.
            DB::table('final_outputs')->delete();
            DB::table('audit_lines')->delete();
            DB::table('audits')->delete();
        }
    }

    public function test_a_drive_or_a_user_principal_is_enough_but_neither_is_not(): void
    {
        Http::fake();
        $this->useGraph(['onedrive.drive_id' => null, 'onedrive.user_principal' => null]);

        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $output = $this->makeFinalOutput($user);

        $this->assertStringContainsString(
            'not been configured',
            (string) $this->share($user, $output)->json('message')
        );
        Http::assertNothingSent();
    }

    // ------------------------------------------------ 2. authentication fails

    public function test_a_rejected_client_secret_is_reported_readably_and_never_echoed(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'error' => 'invalid_client',
                'error_description' => 'AADSTS7000215: Invalid client secret provided.',
            ], 401),
        ]);

        $this->useGraph();
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $output = $this->makeFinalOutput($user);

        $response = $this->share($user, $output);

        $response->assertStatus(502);
        $this->assertStringContainsString('could not sign in', (string) $response->json('message'));

        // The secret we sent must not come back out anywhere.
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());

        $output = $output->fresh();
        $this->assertSame(FinalOutput::ONEDRIVE_FAILED, $output->onedrive_status);
        $this->assertStringNotContainsString(self::SECRET, (string) $output->last_error);
        $this->assertEquals(1, $output->upload_attempts);
    }

    /**
     * An administrator staring at "could not sign in" needs something to act
     * on. The reason Azure gave belongs in the log — the secret does not.
     */
    public function test_the_sign_in_failure_is_logged_with_the_reason_but_not_the_secret(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'error' => 'invalid_client',
                'error_description' => 'AADSTS7000215: Invalid client secret provided.',
            ], 401),
        ]);

        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = $message->message.' '.json_encode($message->context);
        });

        $this->useGraph();
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $this->share($user, $this->makeFinalOutput($user));

        $all = implode("\n", $logged);

        $this->assertStringContainsString('AADSTS7000215', $all, 'The Azure reason was not logged, leaving nothing to diagnose.');
        $this->assertStringNotContainsString(self::SECRET, $all, 'The client secret was written to the log.');
    }

    // ----------------------------------------------------- 3. successful path

    public function test_a_small_file_is_uploaded_and_recorded(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response($this->tokenOk()),
            'graph.microsoft.com/*' => Http::response([
                'id' => '01ABCDEF-ITEM-ID',
                'webUrl' => 'https://contoso-my.sharepoint.com/personal/x/Documents/PharmaVerify/f.xlsx',
            ]),
        ]);

        $this->useGraph();
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $output = $this->makeFinalOutput($user);

        $response = $this->share($user, $output);

        $response->assertOk();

        $output = $output->fresh();
        $this->assertSame(FinalOutput::ONEDRIVE_UPLOADED, $output->onedrive_status);
        $this->assertSame('01ABCDEF-ITEM-ID', $output->onedrive_item_id);
        $this->assertNotNull($output->uploaded_at);
        $this->assertNull($output->last_error);

        // A single PUT to the drive, carrying the bearer token.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'graph.microsoft.com')
                && str_contains($request->url(), 'PharmaVerify/FinalOutput')
                && $request->method() === 'PUT'
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN);
        });

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'onedrive',
            'description' => 'Final output shared to OneDrive',
        ]);
    }

    public function test_the_configured_folder_is_used_and_the_file_name_is_url_encoded(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response($this->tokenOk()),
            'graph.microsoft.com/*' => Http::response(['id' => 'X', 'webUrl' => 'https://example.test/x']),
        ]);

        $this->useGraph(['onedrive.folder' => 'Audits/Final Outputs']);
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $output = $this->makeFinalOutput($user);
        $output->update(['file_name' => 'PHM001 Audit #1.xlsx']);

        $this->share($user, $output)->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'Audits/Final%20Outputs')
                && str_contains($request->url(), 'PHM001%20Audit%20%231.xlsx');
        });
    }

    // ------------------------------------------------------ 4. Graph rejects

    public function test_a_permission_error_from_graph_becomes_a_readable_message(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response($this->tokenOk()),
            'graph.microsoft.com/*' => Http::response([
                'error' => ['code' => 'accessDenied', 'message' => 'Access denied'],
            ], 403),
        ]);

        $this->useGraph();
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $output = $this->makeFinalOutput($user);

        $response = $this->share($user, $output);

        $response->assertStatus(502);
        $this->assertStringContainsString('does not have permission', (string) $response->json('message'));

        $output = $output->fresh();
        $this->assertSame(FinalOutput::ONEDRIVE_FAILED, $output->onedrive_status);
        $this->assertNotNull($output->last_error);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'onedrive',
            'description' => 'OneDrive upload failed',
        ]);
    }

    public function test_a_missing_destination_folder_is_named_as_the_problem(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response($this->tokenOk()),
            'graph.microsoft.com/*' => Http::response([
                'error' => ['code' => 'itemNotFound', 'message' => 'Not found'],
            ], 404),
        ]);

        $this->useGraph();
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $this->assertStringContainsString(
            'destination folder could not be found',
            (string) $this->share($user, $this->makeFinalOutput($user))->json('message')
        );
    }

    public function test_being_throttled_by_graph_asks_the_user_to_retry_rather_than_showing_a_code(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response($this->tokenOk()),
            'graph.microsoft.com/*' => Http::response([
                'error' => ['code' => 'activityLimitReached', 'message' => 'Throttled'],
            ], 429),
        ]);

        $this->useGraph();
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $message = (string) $this->share($user, $this->makeFinalOutput($user))->json('message');

        $this->assertStringContainsString('too many', strtolower($message));
        $this->assertStringNotContainsString('activityLimitReached', $message);
    }

    // -------------------------------------------------- 5. chunked behaviour

    public function test_a_file_over_four_megabytes_goes_through_an_upload_session(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response($this->tokenOk()),
            'graph.microsoft.com/*createUploadSession' => Http::response([
                'uploadUrl' => 'https://graph.microsoft.com/upload-session/abc',
            ]),
            'graph.microsoft.com/upload-session/*' => Http::response([
                'id' => 'CHUNKED-ITEM-ID',
                'webUrl' => 'https://example.test/big.xlsx',
            ]),
        ]);

        $this->useGraph();
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $output = $this->makeFinalOutput($user, bytes: 4 * 1024 * 1024 + 1024);

        $this->share($user, $output)->assertOk();

        $this->assertSame('CHUNKED-ITEM-ID', $output->fresh()->onedrive_item_id);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'createUploadSession'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'upload-session') && $r->hasHeader('Content-Range'));
    }

    public function test_a_failed_upload_session_does_not_mark_the_output_as_shared(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response($this->tokenOk()),
            'graph.microsoft.com/*createUploadSession' => Http::response([
                'error' => ['code' => 'quotaLimitReached', 'message' => 'Full'],
            ], 507),
        ]);

        $this->useGraph();
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $output = $this->makeFinalOutput($user, bytes: 4 * 1024 * 1024 + 1024);

        $this->assertStringContainsString(
            'enough space',
            (string) $this->share($user, $output)->json('message')
        );
        $this->assertSame(FinalOutput::ONEDRIVE_FAILED, $output->fresh()->onedrive_status);
    }

    // ------------------------------------------------------------ 6. the token

    public function test_the_access_token_is_reused_rather_than_fetched_for_every_upload(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response($this->tokenOk()),
            'graph.microsoft.com/*' => Http::response(['id' => 'X', 'webUrl' => 'https://example.test/x']),
        ]);

        $this->useGraph();
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $first = $this->makeFinalOutput($user);
        $this->share($user, $first)->assertOk();

        $second = $this->makeFinalOutput($user);
        $this->share($user, $second)->assertOk();

        $tokenCalls = 0;
        Http::assertSent(function ($request) use (&$tokenCalls) {
            if (str_contains($request->url(), 'login.microsoftonline.com')) {
                $tokenCalls++;
            }

            return true;
        });

        $this->assertSame(1, $tokenCalls, 'Azure AD was asked for a token more than once for two consecutive uploads.');
    }

    public function test_a_rejected_token_is_discarded_so_the_next_attempt_signs_in_again(): void
    {
        $this->useGraph();
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        Http::fake([
            'login.microsoftonline.com/*' => Http::response($this->tokenOk()),
            'graph.microsoft.com/*' => Http::response([
                'error' => ['code' => 'unauthenticated', 'message' => 'Token expired'],
            ], 401),
        ]);

        $output = $this->makeFinalOutput($user);
        $this->share($user, $output)->assertStatus(502);

        // A 401 means the cached token is no longer any use — it must not be
        // handed out again, or every later attempt fails the same way.
        $this->assertNull(
            Cache::get('onedrive.graph.token.'.sha1(self::TENANT.'|'.self::CLIENT)),
            'A token rejected by Graph was left in the cache.'
        );
    }

    // ------------------------------------------------------------- 7. access

    public function test_a_user_without_the_share_permission_is_refused_by_the_api(): void
    {
        Http::fake();
        $this->useGraph();

        $admin = $this->userWithRole(Roles::ADMINISTRATOR);
        $output = $this->makeFinalOutput($admin);

        $shopUser = $this->userWithRole(Roles::SHOP_USER);

        $this->actingAs($shopUser)
            ->postJson("/api/final-outputs/{$output->id}/share-onedrive")
            ->assertStatus(403);

        Http::assertNothingSent();
        $this->assertSame(FinalOutput::ONEDRIVE_NOT_UPLOADED, $output->fresh()->onedrive_status);
    }

    public function test_an_unauthenticated_request_cannot_reach_the_share_action(): void
    {
        Http::fake();
        $this->useGraph();

        $admin = $this->userWithRole(Roles::ADMINISTRATOR);
        $output = $this->makeFinalOutput($admin);

        $this->postJson("/api/final-outputs/{$output->id}/share-onedrive")->assertStatus(401);

        Http::assertNothingSent();
    }

    // ------------------------------------------- 8. explicit share and re-share

    public function test_generating_a_final_output_uploads_nothing(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response($this->tokenOk()),
            'graph.microsoft.com/*' => Http::response(['id' => 'X', 'webUrl' => 'https://example.test/x']),
        ]);

        $this->useGraph();
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $shop = $this->makeShop();
        $device = $this->makeDevice($shop);
        $item = $this->makeItem();
        $stock = $this->makeStock($shop, $item);

        $audit = Audit::create([
            'shop_id' => $shop->id, 'device_id' => $device->id, 'audit_number' => 7,
            'audit_date' => '2026-08-26', 'submitted_at' => now(), 'item_count' => 1,
            'status' => Audit::STATUS_VERIFIED,
        ]);

        AuditLine::create([
            'audit_id' => $audit->id, 'shop_id' => $shop->id, 'item_stock_id' => $stock->id,
            'product_code' => $item->product_code, 'description' => $item->description,
            'system_qty' => 100, 'physical_qty' => 100, 'variance_qty' => 0,
            'verification_status' => 'verified',
        ]);

        $this->actingAs($user)
            ->postJson('/api/final-outputs', ['audit_id' => $audit->id])
            ->assertCreated();

        // The whole rule in one assertion: generating is not sharing.
        Http::assertNothingSent();

        $this->assertSame(
            FinalOutput::ONEDRIVE_NOT_UPLOADED,
            FinalOutput::firstOrFail()->onedrive_status
        );
    }

    public function test_an_output_already_shared_is_not_uploaded_a_second_time(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response($this->tokenOk()),
            'graph.microsoft.com/*' => Http::response(['id' => 'X', 'webUrl' => 'https://example.test/x']),
        ]);

        $this->useGraph();
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $output = $this->makeFinalOutput($user);

        $this->share($user, $output)->assertOk();

        $uploadsAfterFirst = 0;
        Http::assertSent(function ($r) use (&$uploadsAfterFirst) {
            if (str_contains($r->url(), 'graph.microsoft.com')) {
                $uploadsAfterFirst++;
            }

            return true;
        });

        $this->share($user, $output)->assertStatus(422);

        $count = 0;
        Http::assertSent(function ($r) use (&$count) {
            if (str_contains($r->url(), 'graph.microsoft.com')) {
                $count++;
            }

            return true;
        });

        $this->assertSame($uploadsAfterFirst, $count, 'A second share attempt reached Graph despite the re-share rule.');
        $this->assertEquals(1, $output->fresh()->upload_attempts);
    }

    public function test_a_failed_share_may_be_retried(): void
    {
        $this->useGraph();
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $output = $this->makeFinalOutput($user);

        // One stub set, two answers in order: Graph fails, then succeeds.
        Http::fake([
            'login.microsoftonline.com/*' => Http::response($this->tokenOk()),
            'graph.microsoft.com/*' => Http::sequence()
                ->push(['error' => ['code' => 'generalException']], 500)
                ->push(['id' => 'RETRIED', 'webUrl' => 'https://example.test/r'], 200),
        ]);

        $this->share($user, $output)->assertStatus(502);
        $this->assertSame(FinalOutput::ONEDRIVE_FAILED, $output->fresh()->onedrive_status);

        $this->share($user, $output)->assertOk();

        $output = $output->fresh();
        $this->assertSame(FinalOutput::ONEDRIVE_UPLOADED, $output->onedrive_status);
        $this->assertSame('RETRIED', $output->onedrive_item_id);
        $this->assertEquals(2, $output->upload_attempts);
    }

    // -------------------------------------------------------- 9. no leakage

    public function test_no_credential_or_token_reaches_the_api_response_or_the_database(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response($this->tokenOk()),
            'graph.microsoft.com/*' => Http::response([
                'error' => [
                    'code' => 'accessDenied',
                    'message' => 'Access denied',
                    // Graph would never do this, but if it echoed our token back
                    // we must still not pass it on to the user or store it.
                    'innerError' => ['request-id' => 'abc', 'echo' => 'Bearer '.self::TOKEN],
                ],
            ], 403),
        ]);

        $this->useGraph();
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $output = $this->makeFinalOutput($user);

        $body = $this->share($user, $output)->getContent();

        foreach ([self::SECRET, self::TOKEN, 'Bearer '] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "'{$forbidden}' leaked into the API response.");
        }

        $stored = (string) $output->fresh()->last_error;
        foreach ([self::SECRET, self::TOKEN] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $stored, "'{$forbidden}' was stored on the record.");
        }

        $activity = DB::table('activity_log')->where('log_name', 'onedrive')->value('properties');
        foreach ([self::SECRET, self::TOKEN] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $activity, "'{$forbidden}' was written to the activity log.");
        }
    }

    public function test_the_demo_driver_remains_available_for_local_use(): void
    {
        config(['onedrive.driver' => 'demo']);
        Http::fake();

        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $output = $this->makeFinalOutput($user);

        $this->share($user, $output)->assertOk();

        $this->assertSame(FinalOutput::ONEDRIVE_UPLOADED, $output->fresh()->onedrive_status);

        // The demo driver is local only; it must never call Microsoft.
        Http::assertNothingSent();
    }
}
