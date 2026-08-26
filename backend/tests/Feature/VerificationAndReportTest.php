<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\AuditLine;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class VerificationAndReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_correcting_a_count_recomputes_the_variance_and_is_logged(): void
    {
        [$user, $line] = $this->countedLine(100, 95);

        $response = $this->actingAs($user)->patchJson("/api/verification/lines/{$line->id}", [
            'physical_qty' => 98,
            'remarks' => 'Recounted with the shelf supervisor',
        ]);

        $response->assertOk();

        $line = $line->fresh();

        $this->assertEquals(98, $line->physical_qty);
        $this->assertEquals(-2, $line->variance_qty, 'Variance is recomputed, never taken from the request.');
        $this->assertSame(AuditLine::VERIFICATION_VERIFIED, $line->verification_status);
        $this->assertEquals($user->id, $line->verified_by);

        $activity = Activity::where('log_name', 'verification')->latest('id')->firstOrFail();
        $changes = $activity->properties['changes'] ?? [];

        $this->assertArrayHasKey('physical_qty', $changes);
        $this->assertEquals(95, $changes['physical_qty']['old']);
        $this->assertEquals(98, $changes['physical_qty']['new']);
    }

    public function test_a_user_without_the_permission_cannot_edit_a_counted_line(): void
    {
        [, $line] = $this->countedLine(100, 95);

        $shopUser = $this->userWithRole(Roles::SHOP_USER);
        $shopUser->shops()->attach($line->shop_id);

        $this->actingAs($shopUser)
            ->patchJson("/api/verification/lines/{$line->id}", ['physical_qty' => 1])
            ->assertStatus(403);

        $this->assertEquals(95, $line->fresh()->physical_qty);
    }

    public function test_a_negative_physical_quantity_is_refused_during_verification(): void
    {
        [$user, $line] = $this->countedLine(100, 95);

        $this->actingAs($user)
            ->patchJson("/api/verification/lines/{$line->id}", ['physical_qty' => -1])
            ->assertStatus(422);

        $this->assertEquals(95, $line->fresh()->physical_qty);
    }

    public function test_verifying_the_audit_marks_every_outstanding_line(): void
    {
        [$user, $line] = $this->countedLine(100, 95);

        $this->actingAs($user)->postJson("/api/audits/{$line->audit_id}/verify")->assertOk();

        $this->assertSame(AuditLine::VERIFICATION_VERIFIED, $line->fresh()->verification_status);
        $this->assertSame(Audit::STATUS_VERIFIED, Audit::find($line->audit_id)->status);
    }

    public function test_the_variance_screen_summarises_short_excess_and_matched_lines(): void
    {
        $user = $this->userWithRole(Roles::SUPERVISOR);
        $shop = $this->makeShop();
        $device = $this->makeDevice($shop);
        $item = $this->makeItem();
        $stock = $this->makeStock($shop, $item, 100);

        $audit = Audit::create([
            'shop_id' => $shop->id,
            'device_id' => $device->id,
            'audit_number' => 1,
            'audit_date' => '2026-08-25',
            'submitted_at' => now(),
            'status' => Audit::STATUS_SUBMITTED,
        ]);

        foreach ([[100, 95], [100, 103], [100, 100]] as $index => [$system, $physical]) {
            AuditLine::create([
                'audit_id' => $audit->id,
                'shop_id' => $shop->id,
                'item_stock_id' => $stock->id,
                'product_code' => 'MED-100'.$index,
                'description' => 'Product '.$index,
                'system_qty' => $system,
                'physical_qty' => $physical,
                'variance_qty' => AuditLine::calculateVariance($physical, $system),
            ]);
        }

        $response = $this->actingAs($user)->getJson('/api/variance?variance=non_zero');

        $response->assertOk();
        $this->assertSame(2, $response->json('meta.total'), 'Only the lines that differ are listed.');

        $summary = $response->json('meta.summary');
        $this->assertSame(1, $summary['negative_count']);
        $this->assertSame(1, $summary['positive_count']);
        $this->assertSame(1, $summary['zero_count']);
        $this->assertEquals(-2, $summary['net_variance']);
    }

    public function test_every_report_runs_and_can_be_exported(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        [, $line] = $this->countedLine(100, 95, $user);

        $catalogue = $this->actingAs($user)->getJson('/api/reports');
        $catalogue->assertOk();

        $keys = collect($catalogue->json('data'))->pluck('key');
        $this->assertCount(9, $keys, 'All nine reports must be available.');

        foreach ($keys as $key) {
            $this->actingAs($user)->getJson("/api/reports/{$key}?per_page=5")->assertOk();
        }

        $this->assertNotNull($line->id);

        // Excel and PDF come from the same report description as the screen.
        $excel = $this->actingAs($user)->get('/api/reports/variance?format=xlsx');
        $excel->assertOk();
        $this->assertStringStartsWith('PK', $excel->streamedContent());

        // dompdf returns a plain response rather than a stream.
        $pdf = $this->actingAs($user)->get('/api/reports/variance?format=pdf');
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_an_unknown_report_key_returns_a_readable_error(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);

        $response = $this->actingAs($user)->getJson('/api/reports/not-a-report');

        $response->assertStatus(404);
        $this->assertSame('The requested report does not exist.', $response->json('message'));
    }

    /**
     * @return array{0: \App\Models\User, 1: AuditLine}
     */
    private function countedLine(float $system, float $physical, ?\App\Models\User $user = null): array
    {
        $user ??= $this->userWithRole(Roles::SUPERVISOR);
        $shop = $this->makeShop();
        $device = $this->makeDevice($shop);
        $item = $this->makeItem();
        $stock = $this->makeStock($shop, $item, $system);

        $audit = Audit::create([
            'shop_id' => $shop->id,
            'device_id' => $device->id,
            'audit_number' => 1,
            'audit_date' => '2026-08-25',
            'submitted_at' => now(),
            'item_count' => 1,
            'status' => Audit::STATUS_SUBMITTED,
        ]);

        $line = AuditLine::create([
            'audit_id' => $audit->id,
            'shop_id' => $shop->id,
            'item_stock_id' => $stock->id,
            'product_code' => $item->product_code,
            'barcode' => $item->barcode,
            'description' => $item->description,
            'system_qty' => $system,
            'physical_qty' => $physical,
            'variance_qty' => AuditLine::calculateVariance($physical, $system),
            'batch' => 'B001',
        ]);

        return [$user, $line];
    }
}
