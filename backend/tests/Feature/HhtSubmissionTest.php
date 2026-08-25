<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\AuditLine;
use App\Models\HhtSubmission;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HhtSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_completed_count_creates_an_audit_and_its_lines(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = $this->makeShop();
        $device = $this->makeDevice($shop);
        $item = $this->makeItem();
        $this->makeStock($shop, $item, 100);

        $response = $this->actingAs($user)->postJson('/api/hht/submissions', [
            'submission_uid' => 'SUB-001',
            'shop_code' => 'PHM001',
            'device_code' => 'HHT-01',
            'audit_number' => 1,
            'audit_date' => '2026-08-25',
            'hht_user' => 'Karthik Subramani',
            'items' => [
                ['product_code' => 'MED-1001', 'physical_quantity' => 95, 'batch' => 'B001'],
            ],
        ]);

        $response->assertCreated()->assertJsonPath('data.status', HhtSubmission::STATUS_ACCEPTED);

        $audit = Audit::firstOrFail();

        $this->assertSame($shop->id, $audit->shop_id);
        $this->assertSame($device->id, $audit->device_id);
        $this->assertSame(1, $audit->audit_number);
        $this->assertSame(1, $audit->item_count);
        $this->assertSame(1, $audit->variance_count);

        $line = AuditLine::firstOrFail();

        $this->assertEquals(100, $line->system_qty);
        $this->assertEquals(95, $line->physical_qty);
        $this->assertEquals(-5, $line->variance_qty);
    }

    public function test_resending_the_same_submission_does_not_create_a_duplicate_audit(): void
    {
        $user = $this->userWithRole();
        $shop = $this->makeShop();
        $this->makeDevice($shop);
        $item = $this->makeItem();
        $this->makeStock($shop, $item, 100);

        $payload = [
            'submission_uid' => 'SUB-RETRY-001',
            'shop_code' => 'PHM001',
            'device_code' => 'HHT-01',
            'audit_number' => 4,
            'audit_date' => '2026-08-25',
            'items' => [['product_code' => 'MED-1001', 'physical_quantity' => 95, 'batch' => 'B001']],
        ];

        $first = $this->actingAs($user)->postJson('/api/hht/submissions', $payload);
        $first->assertCreated();

        $second = $this->actingAs($user)->postJson('/api/hht/submissions', $payload);

        $second->assertOk()->assertJsonPath('data.status', HhtSubmission::STATUS_DUPLICATE);
        $this->assertSame(
            $first->json('data.audit_id'),
            $second->json('data.audit_id'),
            'The retry must return the audit that already exists.'
        );

        $this->assertSame(1, Audit::count());
        $this->assertSame(1, AuditLine::count());
    }

    public function test_the_same_audit_number_is_accepted_on_different_devices_of_one_shop(): void
    {
        $user = $this->userWithRole();
        $shop = $this->makeShop();
        $this->makeDevice($shop, 'HHT-01');
        $this->makeDevice($shop, 'HHT-02');
        $item = $this->makeItem();
        $this->makeStock($shop, $item, 100);

        foreach (['HHT-01', 'HHT-02'] as $deviceCode) {
            $this->actingAs($user)->postJson('/api/hht/submissions', [
                'submission_uid' => 'SUB-'.$deviceCode,
                'shop_code' => 'PHM001',
                'device_code' => $deviceCode,
                'audit_number' => 2,
                'audit_date' => '2026-08-25',
                'items' => [['product_code' => 'MED-1001', 'physical_quantity' => 100, 'batch' => 'B001']],
            ])->assertCreated();
        }

        // Audit number 2 belongs to two separate audits — one per device.
        $this->assertSame(2, Audit::where('audit_number', 2)->count());
    }

    public function test_a_second_count_under_the_same_audit_number_on_one_device_is_refused(): void
    {
        $user = $this->userWithRole();
        $shop = $this->makeShop();
        $this->makeDevice($shop);
        $item = $this->makeItem();
        $this->makeStock($shop, $item, 100);

        $this->actingAs($user)->postJson('/api/hht/submissions', [
            'submission_uid' => 'SUB-A',
            'shop_code' => 'PHM001',
            'device_code' => 'HHT-01',
            'audit_number' => 5,
            'audit_date' => '2026-08-25',
            'items' => [['product_code' => 'MED-1001', 'physical_quantity' => 100, 'batch' => 'B001']],
        ])->assertCreated();

        $this->actingAs($user)->postJson('/api/hht/submissions', [
            'submission_uid' => 'SUB-B',
            'shop_code' => 'PHM001',
            'device_code' => 'HHT-01',
            'audit_number' => 5,
            'audit_date' => '2026-08-25',
            'items' => [['product_code' => 'MED-1001', 'physical_quantity' => 80, 'batch' => 'B001']],
        ])->assertStatus(409);

        $this->assertSame(1, Audit::count());
    }

    public function test_an_unknown_device_is_rejected_with_a_readable_message(): void
    {
        $user = $this->userWithRole();
        $this->makeShop();

        $response = $this->actingAs($user)->postJson('/api/hht/submissions', [
            'shop_code' => 'PHM001',
            'device_code' => 'HHT-99',
            'audit_number' => 1,
            'audit_date' => '2026-08-25',
            'items' => [['product_code' => 'MED-1001', 'physical_quantity' => 10]],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('not registered', $response->json('message'));
    }

    public function test_a_negative_physical_quantity_is_rejected(): void
    {
        $user = $this->userWithRole();
        $shop = $this->makeShop();
        $this->makeDevice($shop);

        $this->actingAs($user)->postJson('/api/hht/submissions', [
            'shop_code' => 'PHM001',
            'device_code' => 'HHT-01',
            'audit_number' => 1,
            'audit_date' => '2026-08-25',
            'items' => [['product_code' => 'MED-1001', 'physical_quantity' => -4]],
        ])->assertStatus(422);
    }

    public function test_a_counted_product_missing_from_the_stock_file_is_flagged_as_unknown(): void
    {
        $user = $this->userWithRole();
        $shop = $this->makeShop();
        $this->makeDevice($shop);

        $this->actingAs($user)->postJson('/api/hht/submissions', [
            'submission_uid' => 'SUB-UNKNOWN',
            'shop_code' => 'PHM001',
            'device_code' => 'HHT-01',
            'audit_number' => 1,
            'audit_date' => '2026-08-25',
            'items' => [
                ['barcode' => '8901234599999', 'description' => 'Rabeprazole 20mg Tablet', 'physical_quantity' => 24],
            ],
        ])->assertCreated();

        $line = AuditLine::firstOrFail();

        $this->assertTrue($line->is_unknown_item);
        $this->assertEquals(0, $line->system_qty);
        $this->assertEquals(24, $line->variance_qty);
    }
}
