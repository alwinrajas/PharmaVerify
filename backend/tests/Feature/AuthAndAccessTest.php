<?php

namespace Tests\Feature;

use App\Models\FinalOutput;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthAndAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_credentials_return_a_token_and_the_users_permissions(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR, ['email' => 'admin@pharmaverify.test']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@pharmaverify.test',
            'password' => 'Pharma@2026',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertContains('adjustments.create', $response->json('data.user.permissions'));
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_invalid_credentials_are_refused_without_a_token(): void
    {
        $this->userWithRole(Roles::ADMINISTRATOR, ['email' => 'admin@pharmaverify.test']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@pharmaverify.test',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $this->assertNull($response->json('data.token'));
        $this->assertStringContainsString('incorrect', $response->json('errors.email.0'));
    }

    public function test_a_deactivated_user_cannot_sign_in(): void
    {
        $this->userWithRole(Roles::SUPERVISOR, [
            'email' => 'left@pharmaverify.test',
            'status' => 'inactive',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'left@pharmaverify.test',
            'password' => 'Pharma@2026',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('deactivated', $response->json('errors.email.0'));
    }

    public function test_the_api_refuses_an_unauthenticated_request(): void
    {
        $this->getJson('/api/audits')->assertStatus(401);
    }

    public function test_a_shop_user_only_sees_their_assigned_shops(): void
    {
        $shopOne = $this->makeShop('PHM001');
        $shopTwo = $this->makeShop('PHM002');
        $item = $this->makeItem();

        $this->makeStock($shopOne, $item, 100, 'B001');
        $this->makeStock($shopTwo, $item, 200, 'B002');

        $user = $this->userWithRole(Roles::SHOP_USER);
        $user->shops()->attach($shopOne->id);

        $shops = $this->actingAs($user)->getJson('/api/shops');
        $shops->assertOk();
        $this->assertSame(1, $shops->json('meta.total'));
        $this->assertSame('PHM001', $shops->json('data.0.shop_code'));

        $stock = $this->actingAs($user)->getJson('/api/item-stocks');
        $stock->assertOk();
        $this->assertSame(1, $stock->json('meta.total'));
        $this->assertSame('PHM001', $stock->json('data.0.shop_code'));
    }

    public function test_a_supervisor_sees_every_shop(): void
    {
        $this->makeShop('PHM001');
        $this->makeShop('PHM002');

        $user = $this->userWithRole(Roles::SUPERVISOR);

        $this->actingAs($user)->getJson('/api/shops')->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_user_management_is_closed_to_everyone_but_administrators(): void
    {
        $supervisor = $this->userWithRole(Roles::SUPERVISOR);
        $administrator = $this->userWithRole(Roles::ADMINISTRATOR);

        $this->actingAs($supervisor)->getJson('/api/users')->assertStatus(403);
        $this->actingAs($administrator)->getJson('/api/users')->assertOk();
    }

    public function test_the_last_active_administrator_cannot_be_deactivated(): void
    {
        $administrator = $this->userWithRole(Roles::ADMINISTRATOR);

        $response = $this->actingAs($administrator)->postJson("/api/users/{$administrator->id}/toggle-status");

        $response->assertStatus(422);
        $this->assertSame('active', $administrator->fresh()->status);
    }

    public function test_a_failure_returns_a_readable_message_rather_than_a_trace(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $response = $this->actingAs($user)->getJson('/api/audits/999999');

        $response->assertStatus(404);
        $this->assertSame('The requested record could not be found.', $response->json('message'));
        $this->assertArrayNotHasKey('exception', $response->json());
        $this->assertArrayNotHasKey('trace', $response->json());
    }

    public function test_a_final_output_is_not_uploaded_until_the_user_asks(): void
    {
        Storage::fake('local');

        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = $this->makeShop();
        $device = $this->makeDevice($shop);
        $item = $this->makeItem();
        $stock = $this->makeStock($shop, $item, 100);

        $audit = \App\Models\Audit::create([
            'shop_id' => $shop->id,
            'device_id' => $device->id,
            'audit_number' => 1,
            'audit_date' => '2026-08-25',
            'submitted_at' => now(),
            'item_count' => 1,
            'status' => \App\Models\Audit::STATUS_VERIFIED,
        ]);

        \App\Models\AuditLine::create([
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

        $generate = $this->actingAs($user)->postJson('/api/final-outputs', ['audit_id' => $audit->id]);
        $generate->assertCreated();

        $output = FinalOutput::firstOrFail();
        $this->assertSame(FinalOutput::ONEDRIVE_NOT_UPLOADED, $output->onedrive_status);
        $this->assertSame(0, $output->upload_attempts);

        // Only the explicit share action uploads.
        $share = $this->actingAs($user)->postJson("/api/final-outputs/{$output->id}/share-onedrive");
        $share->assertOk();

        $output = $output->fresh();
        $this->assertSame(FinalOutput::ONEDRIVE_UPLOADED, $output->onedrive_status);
        $this->assertSame(1, $output->upload_attempts);
        $this->assertNotNull($output->uploaded_at);

        // Sharing twice is refused rather than silently repeated.
        $this->actingAs($user)
            ->postJson("/api/final-outputs/{$output->id}/share-onedrive")
            ->assertStatus(422);
    }
}
