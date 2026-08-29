<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\AuditLine;
use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Shop;
use App\Models\StockAdjustment;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * The whole flow, once, against the rules as confirmed.
 *
 * Stock Report in, handheld export in, variance out, adjustment posted, reports
 * exported. Each step is covered in depth elsewhere; this walks the join
 * between them, which is where a convention agreed in one place and forgotten
 * in another would show up.
 */
class EndToEndFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_erp_stock_to_handheld_count_to_adjusted_stock(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = Shop::create([
            'shop_code' => 'PHM001', 'shop_name' => 'Wellness Pharmacy',
            'ax_location_id' => 'P001', 'status' => 'active',
        ]);
        $device = $this->makeDevice($shop, 'HHT-01');

        // ---------------------------------------------- 1. the ERP snapshot
        $this->actingAs($user)->post('/api/stock-imports', [
            'file' => $this->stockReport(),
        ], ['Accept' => 'application/json'])->assertCreated();

        $stock = ItemStock::where('shop_id', $shop->id)->get()->keyBy('product_code');

        $this->assertCount(2, $stock);
        // SALESPRICE is the price; TOTALCOST is carried, not derived.
        $this->assertEquals(32.5, $stock['MRAQ-00003']->price);
        $this->assertEquals(71.4321, $stock['MRAQ-00003']->total_cost);
        // Whole quantity is fractional and is not rounded.
        $this->assertEquals(0.04, $stock['MRAQ-00087']->whole_qty);
        // GTIN and the 7-digit code are separate identifiers.
        $this->assertSame('08901234500011', $stock['MRAQ-00003']->gtin);
        $this->assertSame('4500011', $stock['MRAQ-00003']->barcode);
        // Q5: system quantity is LOWERQTY, unchanged.
        $this->assertEquals(100, $stock['MRAQ-00003']->system_qty);

        // Stock Import does not touch the item master beyond creating what is
        // missing, and never overwrites.
        $this->assertSame(2, Item::count());

        // -------------------------------------------- 2. the handheld count
        $import = $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $this->auditExport(),
            'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame('audit', $import->json('meta.kind'));

        $audit = Audit::firstOrFail();
        $this->assertSame('AUD-11082026-0002', $audit->audit_ref);
        $this->assertSame(2, $audit->audit_number);
        $this->assertSame(Audit::SOURCE_EXCEL, $audit->source);

        $short = AuditLine::where('product_code', 'MRAQ-00003')->firstOrFail();
        $excess = AuditLine::where('product_code', 'MRAQ-00087')->firstOrFail();

        // 100 held, 90 whole + 4 loose counted ⇒ 6 short, and short is positive.
        $this->assertEquals(90, $short->physical_qty);
        $this->assertEquals(4, $short->loose_qty);
        $this->assertEquals(6, $short->variance_qty);

        // 4 held, 7 counted ⇒ 3 over, and excess is negative.
        $this->assertEquals(-3, $excess->variance_qty);

        // ------------------------------------------------- 3. the variance
        $variance = $this->actingAs($user)->getJson('/api/variance/summary')->assertOk();

        $this->assertSame(1, $variance->json('data.short_count'));
        $this->assertSame(1, $variance->json('data.excess_count'));
        // +6 short and -3 excess nets to +3, a net shortage.
        $this->assertEquals(3, $variance->json('data.net_variance'));

        // ----------------------------------------------- 4. the adjustment
        $this->actingAs($user)->patchJson('/api/verification/lines/'.$short->id, [
            'physical_qty' => 90,
        ])->assertOk();

        $this->actingAs($user)->postJson('/api/adjustments', ['audit_line_id' => $short->id])
            ->assertCreated();

        // System stock closes to what was counted in total — whole plus loose.
        $this->assertEquals(94, $stock['MRAQ-00003']->fresh()->system_qty);

        $adjusted = $short->fresh();
        $this->assertEquals(0, $adjusted->variance_qty);
        $this->assertEquals(
            0,
            AuditLine::calculateVariance((float) $adjusted->physical_qty, (float) $adjusted->loose_qty, (float) $adjusted->system_qty),
            'The zero is genuine, not merely written.'
        );

        $posted = StockAdjustment::firstOrFail();
        $this->assertEquals(100, $posted->old_system_qty);
        $this->assertEquals(94, $posted->new_system_qty);
        $this->assertEquals(6, $posted->variance_qty);

        // --------------------------------------------------- 5. the reports
        foreach (['variance', 'audit-number', 'detailed', 'variance-summary'] as $key) {
            $this->actingAs($user)->getJson('/api/reports/'.$key)->assertOk();
        }

        $export = $this->actingAs($user)->get('/api/reports/detailed?format=xlsx');
        $export->assertOk();
        $this->assertStringContainsString('spreadsheetml', $export->headers->get('content-type'));

        $pdf = $this->actingAs($user)->get('/api/reports/variance?format=pdf');
        $pdf->assertOk();
        $this->assertStringContainsString('pdf', $pdf->headers->get('content-type'));
    }

    /** A second import of the same export changes nothing at all. */
    public function test_re_importing_the_same_export_leaves_the_flow_untouched(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = Shop::create([
            'shop_code' => 'PHM001', 'shop_name' => 'Wellness', 'ax_location_id' => 'P001', 'status' => 'active',
        ]);
        $device = $this->makeDevice($shop, 'HHT-01');

        $this->actingAs($user)->post('/api/stock-imports', ['file' => $this->stockReport()], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $this->auditExport(), 'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertCreated();

        $before = AuditLine::orderBy('id')->pluck('variance_qty')->map(fn ($v) => (float) $v)->all();

        $this->actingAs($user)->post('/api/hht/imports', [
            'file' => $this->auditExport(), 'device_id' => $device->id,
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(1, Audit::count());
        $this->assertSame($before, AuditLine::orderBy('id')->pluck('variance_qty')->map(fn ($v) => (float) $v)->all());
    }

    // ---------------------------------------------------------- fixtures

    private function stockReport(): UploadedFile
    {
        $spreadsheet = new Spreadsheet;

        $this->fill(
            $spreadsheet->getActiveSheet()->setTitle('stock'),
            ['INVENTLOCATIONID', 'ITEMID', 'INVENTBATCHID', 'EXPDATE', 'LOWERQTY', 'HIGHERQTY', 'COSTPERINVUNIT', 'COSTPERPURUNIT', 'TOTALCOST'],
            [
                ['P001', 'MRAQ-00003', 'B-1', 46023, 100, 10, 5.5, 55, 71.4321],
                ['P001', 'MRAQ-00087', 'B-2', 46024, 4, 0.04, 3.25, 32.5, 12.9],
            ]
        );

        $this->fill(
            $spreadsheet->createSheet()->setTitle('all batches'),
            ['ITEMID', 'INVENTBATCHID', 'EXPDATE', 'ITEMBARCODE'],
            [
                ['MRAQ-00003', 'B-1', 46023, '4500011'],
                ['MRAQ-00087', 'B-2', 46024, '4500028'],
            ]
        );

        $this->fill(
            $spreadsheet->createSheet()->setTitle('Item Master'),
            [
                'ITEMID', 'ITEMNAME', 'ITEMBUYERGROUPID', 'INVUNIT', 'COSTPRICE', 'PURCHSASEUNIT', 'PURCHASEPRICE',
                'SALESUNIT', 'SALESPRICE', 'CATEGORYNAME', 'SUBCATEGORYNAME', 'SUBCATEGORYID', 'SUPPLIERID',
                'FACTOR', 'GLOBALTRADEITEMNUMBER',
            ],
            [
                ['MRAQ-00003', 'Paracetamol 500mg Tablet', null, 'STRIP', 9.75, 'STRIP', 0, 'STRIP', 32.5, null, null, null, null, 10, '08901234500011'],
                ['MRAQ-00087', 'Amoxicillin 250mg Capsule', null, 'STRIP', 40, 'STRIP', 0, 'STRIP', 88.0, null, null, null, null, 100, '08901234500028'],
            ]
        );

        return $this->save($spreadsheet, 'Stock report.xlsx');
    }

    private function auditExport(): UploadedFile
    {
        $spreadsheet = new Spreadsheet;

        $this->fill(
            $spreadsheet->getActiveSheet()->setTitle('Audit'),
            [
                'INVENTLOCATIONID', 'AUDITNUMBER', 'ITEMBARCODE', 'ITEMID', 'ITEMNAME', 'INVENTBATCHID',
                'EXPDATE', 'SYSTEMQTY', 'PHYSICALQTY', 'LZQTY', 'VARIANCE', 'VERIFIEDBY', 'VERIFIEDDATE', 'STATUS',
            ],
            [
                // The device's VARIANCE column carries its own sign convention
                // and is deliberately not believed.
                ['P001', 'AUD-11082026-0002', '08901234500011', 'MRAQ-00003', 'Paracetamol 500mg Tablet', 'B-1', 46023, 100, 90, 4, -6, 'Karthik Subramani', 46023, 'verified'],
                ['P001', 'AUD-11082026-0002', '08901234500028', 'MRAQ-00087', 'Amoxicillin 250mg Capsule', 'B-2', 46024, 4, 7, 0, 3, 'Karthik Subramani', 46023, 'verified'],
            ]
        );

        return $this->save($spreadsheet, 'Audit_export.xlsx');
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

    private function save(Spreadsheet $spreadsheet, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pv-e2e-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, $name, null, null, true);
    }
}
