<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\AuditLine;
use App\Models\HhtSubmission;
use App\Models\ItemStock;
use App\Models\Shop;
use App\Models\StockTake;
use App\Models\StockTakeSession;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

/**
 * Reading the handheld's Excel exports.
 *
 * The device has no network path, so this file hand-off is the integration that
 * actually exists. The layouts here are the ones the device's own exporter
 * writes, column for column.
 */
class HhtExcelImportTest extends TestCase
{
    use RefreshDatabase;

    private const AUDIT_HEADER = [
        'INVENTLOCATIONID', 'AUDITNUMBER', 'ITEMBARCODE', 'ITEMID', 'ITEMNAME', 'INVENTBATCHID',
        'EXPDATE', 'SYSTEMQTY', 'PHYSICALQTY', 'LZQTY', 'VARIANCE', 'VERIFIEDBY', 'VERIFIEDDATE', 'STATUS',
    ];

    private const TAKE_HEADER = [
        'INVENTLOCATIONID', 'STOCKTAKENUMBER', 'ITEMBARCODE', 'ITEMID', 'ITEMNAME', 'INVENTBATCHID',
        'EXPDATE', 'PHYSICALQTY', 'LZQTY', 'COUNTEDBY', 'COUNTEDDATE', 'STATUS',
    ];

    // --------------------------------------------------------- detection

    public function test_an_audit_export_is_recognised_as_an_audit(): void
    {
        [$user, $shop, $device] = $this->scenario();

        $response = $this->actingAs($user)->post('/api/hht/imports/preview', [
            'file' => $this->auditFile(),
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame('audit', $response->json('meta.kind'));
        $this->assertSame('AUD-11082026-0002', $response->json('data.reference'));
        $this->assertSame($shop->id, $response->json('data.shop.shop_id'));
        $this->assertSame('create', $response->json('data.action'));
    }

    public function test_a_stock_take_export_is_recognised_as_a_stock_take(): void
    {
        [$user] = $this->scenario();

        $response = $this->actingAs($user)->post('/api/hht/imports/preview', [
            'file' => $this->takeFile(),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame('stock_take', $response->json('meta.kind'));
        $this->assertSame('STK-17082026-0007', $response->json('data.reference'));
    }

    public function test_a_workbook_carrying_both_number_columns_is_refused(): void
    {
        [$user, , $device] = $this->scenario();

        $spreadsheet = new Spreadsheet;
        $this->fill($spreadsheet->getActiveSheet(), array_merge(self::AUDIT_HEADER, ['STOCKTAKENUMBER']), []);

        $this->actingAs($user)->post('/api/hht/imports/preview', [
            'file' => $this->save($spreadsheet),
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, Audit::count());
    }

    public function test_a_file_that_is_not_a_handheld_export_is_refused(): void
    {
        [$user] = $this->scenario();

        $spreadsheet = new Spreadsheet;
        $this->fill($spreadsheet->getActiveSheet(), ['Product Code', 'Description', 'Qty'], [['A', 'B', 1]]);

        $this->actingAs($user)->post('/api/hht/imports/preview', [
            'file' => $this->save($spreadsheet),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_importing_a_take_through_the_audit_path_is_refused(): void
    {
        [$user, , $device] = $this->scenario();

        // Detection is by the file, not by what the caller says, so a take
        // uploaded with a device selected is still read as a take.
        $response = $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $this->takeFile(),
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame('stock_take', $response->json('meta.kind'));
        $this->assertSame(0, Audit::count());
    }

    public function test_a_malformed_reference_is_refused(): void
    {
        [$user, , $device] = $this->scenario();

        $file = $this->auditFile(reference: 'AUDIT-2026-08-11-2');

        $this->actingAs($user)->post('/api/hht/imports/preview', [
            'file' => $file,
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_a_file_covering_two_references_is_refused(): void
    {
        [$user, $shop, $device] = $this->scenario();

        $spreadsheet = new Spreadsheet;
        $this->fill($spreadsheet->getActiveSheet(), self::AUDIT_HEADER, [
            ['P001', 'AUD-11082026-0002', '08901234500011', 'MED-1001', 'Paracetamol', 'B001', 46023, 100, 95, 0, -5, 'Karthik', 46023, 'verified'],
            ['P001', 'AUD-11082026-0003', '08901234500011', 'MED-1002', 'Amoxicillin', 'B002', 46023, 50, 50, 0, 0, 'Karthik', 46023, 'verified'],
        ]);

        $this->actingAs($user)->post('/api/hht/imports/preview', [
            'file' => $this->save($spreadsheet),
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    // ------------------------------------------------------------ import

    public function test_an_audit_import_creates_the_audit_and_its_lines(): void
    {
        [$user, $shop, $device] = $this->scenario();

        $response = $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $this->auditFile(),
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        $audit = Audit::firstOrFail();

        $this->assertSame('AUD-11082026-0002', $audit->audit_ref);
        $this->assertSame(2, $audit->audit_number, 'The numeric tail becomes the audit number.');
        $this->assertSame(Audit::SOURCE_EXCEL, $audit->source);
        $this->assertSame($device->id, $audit->device_id);
        $this->assertSame($shop->id, $audit->shop_id);
        $this->assertSame('Karthik Subramani', $audit->hht_user);

        $this->assertSame(3, AuditLine::count());
        $this->assertSame(3, $response->json('meta.summary.imported'));
    }

    /** The device's variance is on the opposite convention and is not believed. */
    public function test_variance_is_recomputed_rather_than_taken_from_the_file(): void
    {
        [$user, , $device] = $this->scenario();

        // The file claims a variance of 999 for the short line — nonsense that
        // must not survive.
        $file = $this->auditFile(rows: [
            ['P001', 'AUD-11082026-0002', '08901234500011', 'MED-1001', 'Paracetamol', 'B001', 46023, 100, 95, 0, 999, 'Karthik Subramani', 46023, 'verified'],
        ]);

        $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $file,
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        $line = AuditLine::firstOrFail();

        // 100 held - (95 + 0) = 5 short, and short is positive.
        $this->assertEquals(5, $line->variance_qty);
    }

    public function test_loose_quantity_is_imported_and_counts_towards_the_variance(): void
    {
        [$user, , $device] = $this->scenario();

        $file = $this->auditFile(rows: [
            ['P001', 'AUD-11082026-0002', '08901234500011', 'MED-1001', 'Paracetamol', 'B001', 46023, 100, 95, 5, -0, 'Karthik Subramani', 46023, 'verified'],
        ]);

        $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $file,
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        $line = AuditLine::firstOrFail();

        $this->assertEquals(95, $line->physical_qty);
        $this->assertEquals(5, $line->loose_qty);
        // 100 - (95 + 5) = 0. The loose stock closed the gap.
        $this->assertEquals(0, $line->variance_qty);
    }

    public function test_the_files_system_quantity_is_kept_for_reconciliation_but_not_believed(): void
    {
        [$user, $shop, $device] = $this->scenario();

        // PharmaVerify holds 100; the device's copy said 90.
        $file = $this->auditFile(rows: [
            ['P001', 'AUD-11082026-0002', '08901234500011', 'MED-1001', 'Paracetamol', 'B001', 46023, 90, 95, 0, 5, 'Karthik Subramani', 46023, 'verified'],
        ]);

        $response = $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $file,
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        $line = AuditLine::firstOrFail();

        $this->assertEquals(100, $line->system_qty, "PharmaVerify's own figure is authoritative.");
        $this->assertEquals(90, $line->source_system_qty, 'What the file claimed is kept beside it.');
        $this->assertEquals(5, $line->variance_qty, 'Variance uses our figure, not the file\'s.');
        $this->assertSame(1, $response->json('meta.summary.system_qty_disagreements'));
    }

    public function test_a_gtin_resolves_the_item_batch_and_expiry(): void
    {
        [$user, $shop, $device] = $this->scenario();

        $response = $this->actingAs($user)->post('/api/hht/imports/preview', [
            'file' => $this->auditFile(),
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertOk();

        // All three known rows resolved on their GTIN, not the fallback.
        $this->assertSame(3, $response->json('data.matched_on.gtin'));
        $this->assertSame(0, $response->json('data.matched_on.barcode'));
    }

    public function test_the_seven_digit_barcode_still_resolves_when_no_gtin_matches(): void
    {
        [$user, $shop, $device] = $this->scenario();

        ItemStock::create([
            'shop_id' => $shop->id, 'product_code' => 'MED-9999', 'description' => 'Legacy product',
            'gtin' => null, 'barcode' => '4500011', 'system_qty' => 20, 'uom' => 'EA', 'price' => 5,
            'batch' => 'B-LEG', 'expiry_date' => '2027-01-31',
        ]);

        $file = $this->auditFile(rows: [
            ['P001', 'AUD-11082026-0002', '4500011', 'MED-9999', 'Legacy product', 'B-LEG', 46023, 20, 18, 0, -2, 'Karthik Subramani', 46023, 'verified'],
        ]);

        $response = $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $file,
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(1, $response->json('meta.summary.imported'));

        $line = AuditLine::firstOrFail();
        $this->assertFalse((bool) $line->is_unknown_item);
        $this->assertEquals(2, $line->variance_qty);
    }

    public function test_an_unrecognised_product_is_kept_as_unknown_rather_than_dropped(): void
    {
        [$user, , $device] = $this->scenario();

        $file = $this->auditFile(rows: [
            ['P001', 'AUD-11082026-0002', '00000000000000', 'MED-NOPE', 'Never seen', 'B-X', 46023, 0, 7, 0, 7, 'Karthik Subramani', 46023, 'verified'],
        ]);

        $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $file,
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        $line = AuditLine::firstOrFail();

        $this->assertTrue((bool) $line->is_unknown_item);
        $this->assertSame('MED-NOPE', $line->product_code);
        $this->assertEquals(0, $line->system_qty);
        // Nothing on the books, seven on the shelf: an excess, so negative.
        $this->assertEquals(-7, $line->variance_qty);
    }

    // ------------------------------------------------------- validation

    public function test_an_unknown_warehouse_code_is_reported_and_its_rows_are_not_imported(): void
    {
        [$user, $shop, $device] = $this->scenario();

        $file = $this->auditFile(rows: [
            ['P001', 'AUD-11082026-0002', '08901234500011', 'MED-1001', 'Paracetamol', 'B001', 46023, 100, 95, 0, -5, 'Karthik Subramani', 46023, 'verified'],
            ['ZZ99', 'AUD-11082026-0002', '08901234500028', 'MED-1002', 'Amoxicillin', 'B002', 46023, 50, 48, 0, -2, 'Karthik Subramani', 46023, 'verified'],
        ]);

        $response = $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $file,
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(1, $response->json('meta.summary.imported'));
        $this->assertSame(1, $response->json('meta.summary.rejected'));
        $this->assertSame(['ZZ99'], $response->json('meta.summary.unmatched_locations'));

        // The rejected row went nowhere, least of all to another shop.
        $this->assertSame(1, AuditLine::count());
        $this->assertSame('MED-1001', AuditLine::firstOrFail()->product_code);
    }

    public function test_a_file_whose_every_location_is_unknown_is_refused_outright(): void
    {
        [$user, , $device] = $this->scenario();

        $file = $this->auditFile(rows: [
            ['ZZ99', 'AUD-11082026-0002', '08901234500011', 'MED-1001', 'Paracetamol', 'B001', 46023, 100, 95, 0, -5, 'K', 46023, 'verified'],
        ]);

        $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $file,
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, Audit::count());
    }

    public function test_bad_quantities_and_dates_are_rejected_row_by_row(): void
    {
        [$user, , $device] = $this->scenario();

        $file = $this->auditFile(rows: [
            ['P001', 'AUD-11082026-0002', '08901234500011', 'MED-1001', 'Paracetamol', 'B001', 46023, 100, 95, 0, -5, 'K', 46023, 'verified'],
            ['P001', 'AUD-11082026-0002', '08901234500028', 'MED-1002', 'Amoxicillin', 'B002', 46023, 50, 'nope', 0, 0, 'K', 46023, 'verified'],
            ['P001', 'AUD-11082026-0002', '08901234500035', 'MED-1003', 'Azithromycin', 'B003', 46023, 20, -3, 0, 0, 'K', 46023, 'verified'],
            ['P001', 'AUD-11082026-0002', '08901234500011', 'MED-1004', 'Loose bad', 'B004', 46023, 10, 5, 'x', 0, 'K', 46023, 'verified'],
        ]);

        $response = $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $file,
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(1, $response->json('meta.summary.imported'));
        $this->assertSame(3, $response->json('meta.summary.rejected'));
    }

    public function test_a_repeated_item_and_batch_within_one_file_is_rejected_once(): void
    {
        [$user, , $device] = $this->scenario();

        $file = $this->auditFile(rows: [
            ['P001', 'AUD-11082026-0002', '08901234500011', 'MED-1001', 'Paracetamol', 'B001', 46023, 100, 95, 0, -5, 'K', 46023, 'verified'],
            ['P001', 'AUD-11082026-0002', '08901234500011', 'MED-1001', 'Paracetamol', 'B001', 46023, 100, 90, 0, -10, 'K', 46023, 'verified'],
        ]);

        $response = $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $file,
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(1, $response->json('meta.summary.imported'));
        $this->assertSame(1, $response->json('meta.summary.rejected'));
        // The first reading is the one kept.
        $this->assertEquals(95, AuditLine::firstOrFail()->physical_qty);
    }

    public function test_a_device_belonging_to_another_shop_is_refused(): void
    {
        [$user, $shop, $device] = $this->scenario();

        $other = Shop::create(['shop_code' => 'PHM002', 'shop_name' => 'Other', 'ax_location_id' => 'T033', 'status' => 'active']);
        $wrongDevice = $this->makeDevice($other, 'HHT-99');

        $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $this->auditFile(),
            'device_id' => $wrongDevice->id,
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, Audit::count());
    }

    public function test_an_audit_import_without_a_device_is_refused(): void
    {
        [$user] = $this->scenario();

        $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $this->auditFile(),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, Audit::count());
    }

    // ------------------------------------------------------ idempotency

    public function test_the_same_file_twice_creates_one_audit(): void
    {
        [$user, , $device] = $this->scenario();

        $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $this->auditFile(),
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        $second = $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $this->auditFile(),
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertTrue($second->json('meta.duplicate'));
        $this->assertSame(1, Audit::count());
        $this->assertSame(3, AuditLine::count());
        $this->assertSame(1, HhtSubmission::count());
    }

    public function test_the_same_count_in_a_different_row_order_is_still_the_same_file(): void
    {
        [$user, , $device] = $this->scenario();

        $rows = $this->defaultAuditRows();

        $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $this->auditFile(rows: $rows),
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        $second = $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $this->auditFile(rows: array_reverse($rows)),
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertTrue($second->json('meta.duplicate'));
        $this->assertSame(1, Audit::count());
    }

    public function test_the_same_reference_with_changed_quantities_is_refused(): void
    {
        [$user, , $device] = $this->scenario();

        $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $this->auditFile(),
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        $changed = $this->defaultAuditRows();
        $changed[0][8] = 42;

        $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $this->auditFile(rows: $changed),
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertStatus(409);

        // The original stands untouched.
        $this->assertSame(1, Audit::count());
        $this->assertEquals(95, AuditLine::where('product_code', 'MED-1001')->firstOrFail()->physical_qty);
    }

    public function test_a_preview_writes_nothing(): void
    {
        [$user, , $device] = $this->scenario();

        $this->actingAs($user)->post('/api/hht/imports/preview', [
            'file' => $this->auditFile(),
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(0, Audit::count());
        $this->assertSame(0, AuditLine::count());
        $this->assertSame(0, HhtSubmission::count());
    }

    public function test_a_preview_names_the_audit_it_would_clash_with(): void
    {
        [$user, , $device] = $this->scenario();

        $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $this->auditFile(),
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        $changed = $this->defaultAuditRows();
        $changed[0][8] = 42;

        $response = $this->actingAs($user)->post('/api/hht/imports/preview', [
            'file' => $this->auditFile(rows: $changed),
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame('conflict', $response->json('data.action'));
        $this->assertSame('AUD-11082026-0002', $response->json('data.conflict.audit_ref'));
        $this->assertSame('excel', $response->json('data.conflict.source'));
    }

    // ------------------------------------------------- transaction safety

    public function test_a_database_failure_midway_leaves_nothing_behind(): void
    {
        [$user, , $device] = $this->scenario();

        DB::beforeExecuting(function (string $query) {
            if (str_contains(strtolower($query), 'insert into') && str_contains(strtolower($query), 'audit_lines')) {
                throw new RuntimeException('Simulated database failure during the import.');
            }
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user)->post('/api/hht/imports', [
                'file' => $this->auditFile(),
                'device_id' => $device->id,
            ], ['Accept' => 'application/json']);
            $this->fail('The import should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Simulated database failure', $exception->getMessage());
        }

        $this->assertSame(0, Audit::count());
        $this->assertSame(0, AuditLine::count());
        $this->assertSame(0, HhtSubmission::count());
    }

    // -------------------------------------------------------- stock take

    public function test_a_stock_take_import_creates_a_cycle_and_its_lines(): void
    {
        [$user, $shop] = $this->scenario();

        $response = $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $this->takeFile(),
        ], ['Accept' => 'application/json'])->assertCreated();

        $session = StockTakeSession::firstOrFail();

        $this->assertSame('STK-17082026-0007', $session->take_ref);
        $this->assertSame(7, $session->take_number);
        $this->assertSame(StockTakeSession::SOURCE_EXCEL, $session->source);
        $this->assertSame($shop->id, $session->shop_id);
        $this->assertSame(2, $session->item_count);

        $this->assertSame(2, StockTake::count());
        $this->assertSame($session->id, StockTake::first()->stock_take_session_id);
        $this->assertSame(2, $response->json('meta.summary.imported'));
    }

    /** A take is blind: no system quantity is invented for it. */
    public function test_a_stock_take_import_never_derives_a_system_quantity(): void
    {
        [$user, $shop] = $this->scenario();

        $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $this->takeFile(),
        ], ['Accept' => 'application/json'])->assertCreated();

        // The first take line matches a product that PharmaVerify holds 100 of.
        $line = StockTake::where('product_code', 'MED-1001')->firstOrFail();

        $this->assertEquals(12, $line->physical_qty);
        $this->assertEquals(3, $line->loose_qty);

        // No variance and no system quantity exist on a stock take at all.
        $this->assertArrayNotHasKey('system_qty', $line->getAttributes());
        $this->assertArrayNotHasKey('variance_qty', $line->getAttributes());
        $this->assertSame(0, AuditLine::count());
    }

    public function test_the_same_stock_take_file_twice_creates_one_cycle(): void
    {
        [$user] = $this->scenario();

        $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $this->takeFile(),
        ], ['Accept' => 'application/json'])->assertCreated();

        $second = $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $this->takeFile(),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertTrue($second->json('meta.duplicate'));
        $this->assertSame(1, StockTakeSession::count());
        $this->assertSame(2, StockTake::count());
    }

    public function test_a_changed_stock_take_under_the_same_reference_is_refused(): void
    {
        [$user] = $this->scenario();

        $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $this->takeFile(),
        ], ['Accept' => 'application/json'])->assertCreated();

        $changed = $this->defaultTakeRows();
        $changed[0][7] = 99;

        $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $this->takeFile(rows: $changed),
        ], ['Accept' => 'application/json'])->assertStatus(409);

        $this->assertSame(1, StockTakeSession::count());
        $this->assertSame(2, StockTake::count());
    }

    public function test_a_user_without_the_import_permission_is_refused(): void
    {
        $this->scenario();
        $shopUser = $this->userWithRole(Roles::SHOP_USER);

        $this->actingAs($shopUser)->post('/api/hht/imports', [
            'file' => $this->takeFile(),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->assertSame(0, StockTakeSession::count());
    }

    // ---------------------------------------------------------- helpers

    /**
     * A shop with three stocked products, a device, and an administrator.
     *
     * @return array{0: \App\Models\User, 1: Shop, 2: \App\Models\Device}
     */
    private function scenario(): array
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = Shop::create([
            'shop_code' => 'PHM001', 'shop_name' => 'Wellness Pharmacy',
            'ax_location_id' => 'P001', 'status' => 'active',
        ]);
        $device = $this->makeDevice($shop, 'HHT-01');

        foreach ([
            ['MED-1001', '08901234500011', 'Paracetamol 500mg Tablet', 'B001', 100],
            ['MED-1002', '08901234500028', 'Amoxicillin 250mg Capsule', 'B002', 50],
            ['MED-1003', '08901234500035', 'Azithromycin 500mg Tablet', 'B003', 20],
        ] as [$code, $gtin, $description, $batch, $qty]) {
            ItemStock::create([
                'shop_id' => $shop->id,
                'product_code' => $code,
                'gtin' => $gtin,
                'barcode' => substr($gtin, -7),
                'description' => $description,
                'system_qty' => $qty,
                'uom' => 'STRIP',
                'price' => 32.5,
                'batch' => $batch,
                'expiry_date' => '2027-06-30',
            ]);
        }

        return [$user, $shop, $device];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function defaultAuditRows(): array
    {
        return [
            ['P001', 'AUD-11082026-0002', '08901234500011', 'MED-1001', 'Paracetamol 500mg Tablet', 'B001', 46023, 100, 95, 0, -5, 'Karthik Subramani', 46023, 'verified'],
            ['P001', 'AUD-11082026-0002', '08901234500028', 'MED-1002', 'Amoxicillin 250mg Capsule', 'B002', 46023, 50, 50, 0, 0, 'Karthik Subramani', 46023, 'verified'],
            ['P001', 'AUD-11082026-0002', '08901234500035', 'MED-1003', 'Azithromycin 500mg Tablet', 'B003', 46023, 20, 23, 0, 3, 'Karthik Subramani', 46023, 'verified'],
        ];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function defaultTakeRows(): array
    {
        return [
            ['P001', 'STK-17082026-0007', '08901234500011', 'MED-1001', 'Paracetamol 500mg Tablet', 'B001', 46023, 12, 3, 'Karthik Subramani', 46023, 'counted'],
            ['P001', 'STK-17082026-0007', '00000000000000', 'MED-NEW', 'Never seen before', 'B-NEW', 46023, 4, 0, 'Karthik Subramani', 46023, 'counted'],
        ];
    }

    /**
     * @param  array<int, array<int, mixed>>|null  $rows
     */
    private function auditFile(?array $rows = null, string $reference = 'AUD-11082026-0002'): UploadedFile
    {
        $rows ??= $this->defaultAuditRows();

        if ($reference !== 'AUD-11082026-0002') {
            $rows = array_map(function (array $row) use ($reference) {
                $row[1] = $reference;

                return $row;
            }, $rows);
        }

        $spreadsheet = new Spreadsheet;
        $this->fill($spreadsheet->getActiveSheet()->setTitle('Audit'), self::AUDIT_HEADER, $rows);

        return $this->save($spreadsheet, 'Audit_export.xlsx');
    }

    /**
     * @param  array<int, array<int, mixed>>|null  $rows
     */
    private function takeFile(?array $rows = null): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $this->fill($spreadsheet->getActiveSheet()->setTitle('Stock Take'), self::TAKE_HEADER, $rows ?? $this->defaultTakeRows());

        return $this->save($spreadsheet, 'StockTake_export.xlsx');
    }

    /**
     * @param  array<int, string>  $headings
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function fill($sheet, array $headings, array $rows): void
    {
        foreach ($headings as $column => $heading) {
            $sheet->setCellValue([$column + 1, 1], $heading);
        }

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $column => $value) {
                $sheet->setCellValue([$column + 1, $rowIndex + 2], $value);
            }
        }
    }

    private function save(Spreadsheet $spreadsheet, string $name = 'export.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pv-hht-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, $name, null, null, true);
    }
}
