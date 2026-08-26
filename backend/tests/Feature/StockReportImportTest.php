<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Shop;
use App\Models\StockImport;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * The business Stock Report: a Dynamics AX export of three sheets covering
 * every branch it was run for.
 */
class StockReportImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stock_report_imports_every_shop_it_names(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $one = $this->shopFor('PHM001', 'P001');
        $two = $this->shopFor('PHM002', 'T033');

        $file = $this->stockReport(
            stock: [
                ['P001', 'MRAQ-00003', 'B-1', 46023, 12, 12, 5.5],
                ['P001', 'MRAQ-00087', 'B-2', 46024, 7, 7, 3.25],
                ['T033', 'MRAQ-00003', 'B-9', 46025, 4, 4, 5.5],
            ],
            batches: [
                ['MRAQ-00003', 'B-1', 46023, '8901234500011'],
                ['MRAQ-00087', 'B-2', 46024, '8901234500028'],
                ['MRAQ-00003', 'B-9', 46025, '8901234500011'],
            ],
            items: [
                ['MRAQ-00003', 'Paracetamol 500mg Tablet', 'STRIP', 32.5],
                ['MRAQ-00087', 'Amoxicillin 250mg Capsule', 'STRIP', 88.0],
            ],
        );

        $response = $this->actingAs($user)->post('/api/stock-imports', ['file' => $file], ['Accept' => 'application/json']);

        $response->assertCreated();
        $this->assertEquals(2, $response->json('meta.summary.shops'));
        $this->assertEquals(3, $response->json('meta.summary.imported'));
        $this->assertSame('stock_report', $response->json('meta.format'));

        $this->assertSame(2, ItemStock::where('shop_id', $one->id)->count());
        $this->assertSame(1, ItemStock::where('shop_id', $two->id)->count());

        // Details are joined from the other two sheets.
        $line = ItemStock::where('shop_id', $one->id)->where('product_code', 'MRAQ-00003')->firstOrFail();
        $this->assertSame('Paracetamol 500mg Tablet', $line->description);
        $this->assertSame('STRIP', $line->uom);
        $this->assertSame('8901234500011', $line->barcode);
        $this->assertEquals(12, $line->system_qty);
        $this->assertEquals(32.5, $line->price);
        $this->assertSame('2026-01-01', $line->expiry_date->toDateString());
    }

    public function test_it_replaces_only_the_shops_named_in_the_report(): void
    {
        $user = $this->userWithRole();
        $one = $this->shopFor('PHM001', 'P001');
        $untouched = $this->shopFor('PHM003', null);

        $item = $this->makeItem();
        $this->makeStock($one, $item, 100, 'OLD');
        $this->makeStock($untouched, $item, 500, 'KEEP');

        $file = $this->stockReport(
            stock: [['P001', 'MRAQ-00003', 'B-1', 46023, 12, 12, 5.5]],
            batches: [['MRAQ-00003', 'B-1', 46023, '8901234500011']],
            items: [['MRAQ-00003', 'Paracetamol 500mg Tablet', 'STRIP', 32.5]],
        );

        $this->actingAs($user)->post('/api/stock-imports', ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated();

        // Replaced, not appended.
        $this->assertSame(1, ItemStock::where('shop_id', $one->id)->count());
        $this->assertSame(0, ItemStock::where('shop_id', $one->id)->where('batch', 'OLD')->count());

        // A shop the report does not mention keeps everything it had.
        $this->assertSame(1, ItemStock::where('shop_id', $untouched->id)->count());
        $this->assertEquals(500, ItemStock::where('shop_id', $untouched->id)->firstOrFail()->system_qty);
    }

    public function test_importing_the_same_report_twice_does_not_duplicate_stock(): void
    {
        $user = $this->userWithRole();
        $shop = $this->shopFor('PHM001', 'P001');

        $rows = [
            'stock' => [['P001', 'MRAQ-00003', 'B-1', 46023, 12, 12, 5.5]],
            'batches' => [['MRAQ-00003', 'B-1', 46023, '8901234500011']],
            'items' => [['MRAQ-00003', 'Paracetamol 500mg Tablet', 'STRIP', 32.5]],
        ];

        foreach (range(1, 2) as $ignored) {
            $this->actingAs($user)->post('/api/stock-imports', [
                'file' => $this->stockReport($rows['stock'], $rows['batches'], $rows['items']),
            ], ['Accept' => 'application/json'])->assertCreated();
        }

        $this->assertSame(1, ItemStock::where('shop_id', $shop->id)->count(), 'A repeat import replaces rather than duplicates.');
        $this->assertSame(2, StockImport::count(), 'Both attempts are recorded for audit.');
    }

    public function test_a_row_for_an_unmapped_warehouse_is_reported_not_imported(): void
    {
        $user = $this->userWithRole();
        $shop = $this->shopFor('PHM001', 'P001');

        $file = $this->stockReport(
            stock: [
                ['P001', 'MRAQ-00003', 'B-1', 46023, 12, 12, 5.5],
                ['ZZ99', 'MRAQ-00087', 'B-2', 46024, 7, 7, 3.25],
            ],
            batches: [['MRAQ-00003', 'B-1', 46023, '8901234500011']],
            items: [
                ['MRAQ-00003', 'Paracetamol 500mg Tablet', 'STRIP', 32.5],
                ['MRAQ-00087', 'Amoxicillin 250mg Capsule', 'STRIP', 88.0],
            ],
        );

        $response = $this->actingAs($user)->post('/api/stock-imports', ['file' => $file], ['Accept' => 'application/json']);

        $response->assertCreated();
        $this->assertEquals(1, $response->json('meta.summary.imported'));
        $this->assertEquals(1, $response->json('meta.summary.failed'));

        $error = StockImport::firstOrFail()->errors()->firstOrFail();
        $this->assertStringContainsString('ZZ99', $error->error_message);
        $this->assertSame(1, ItemStock::where('shop_id', $shop->id)->count());
    }

    public function test_invalid_rows_are_reported_with_a_reason(): void
    {
        $user = $this->userWithRole();
        $this->shopFor('PHM001', 'P001');

        $file = $this->stockReport(
            stock: [
                ['P001', 'MRAQ-00003', 'B-1', 46023, 12, 12, 5.5],
                ['P001', 'MRAQ-00087', 'B-2', 46024, 'not-a-number', 7, 3.25],
                ['P001', 'MRAQ-00099', 'B-3', 46025, -4, 1, 1.0],
                ['P001', '', 'B-4', 46026, 5, 5, 1.0],
                ['P001', 'MRAQ-00003', 'B-1', 46023, 3, 3, 5.5],
            ],
            batches: [['MRAQ-00003', 'B-1', 46023, '8901234500011']],
            items: [['MRAQ-00003', 'Paracetamol 500mg Tablet', 'STRIP', 32.5]],
        );

        $response = $this->actingAs($user)->post('/api/stock-imports', ['file' => $file], ['Accept' => 'application/json']);

        $response->assertCreated();
        $this->assertEquals(1, $response->json('meta.summary.imported'));
        $this->assertEquals(4, $response->json('meta.summary.failed'));

        $messages = StockImport::firstOrFail()->errors()->pluck('error_message');

        $this->assertTrue($messages->contains(fn ($m) => str_contains($m, 'must be a number')));
        $this->assertTrue($messages->contains(fn ($m) => str_contains($m, 'cannot be negative')));
        $this->assertTrue($messages->contains(fn ($m) => str_contains($m, 'item code is required')));
        $this->assertTrue($messages->contains(fn ($m) => str_contains($m, 'Duplicate')));
    }

    public function test_a_workbook_missing_a_required_sheet_changes_nothing(): void
    {
        $user = $this->userWithRole();
        $shop = $this->shopFor('PHM001', 'P001');
        $item = $this->makeItem();
        $this->makeStock($shop, $item, 100, 'KEEP');

        // Only two of the three sheets, so it is not a Stock Report and has no
        // usable flat layout either.
        $spreadsheet = new Spreadsheet;
        $this->fill($spreadsheet->getActiveSheet()->setTitle('stock'),
            ['INVENTLOCATIONID', 'ITEMID', 'INVENTBATCHID', 'EXPDATE', 'LOWERQTY'],
            [['P001', 'MRAQ-00003', 'B-1', 46023, 12]]);
        $this->fill($spreadsheet->createSheet()->setTitle('all batches'),
            ['ITEMID', 'INVENTBATCHID', 'EXPDATE', 'ITEMBARCODE'], []);

        $response = $this->actingAs($user)->post('/api/stock-imports', [
            'shop_id' => $shop->id,
            'file' => $this->save($spreadsheet),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $this->assertSame(1, ItemStock::where('shop_id', $shop->id)->count());
        $this->assertEquals(100, ItemStock::firstOrFail()->system_qty);
        $this->assertSame(0, StockImport::count());
    }

    public function test_the_item_master_is_synced_from_the_report(): void
    {
        $user = $this->userWithRole();
        $this->shopFor('PHM001', 'P001');

        Item::create([
            'product_code' => 'MRAQ-00003',
            'description' => 'Stale description',
            'uom' => 'EA',
            'price' => 1,
            'status' => 'active',
        ]);

        $file = $this->stockReport(
            stock: [
                ['P001', 'MRAQ-00003', 'B-1', 46023, 12, 12, 5.5],
                ['P001', 'MRAQ-00087', 'B-2', 46024, 7, 7, 3.25],
            ],
            batches: [
                ['MRAQ-00003', 'B-1', 46023, '8901234500011'],
                ['MRAQ-00087', 'B-2', 46024, '8901234500028'],
            ],
            items: [
                ['MRAQ-00003', 'Paracetamol 500mg Tablet', 'STRIP', 32.5],
                ['MRAQ-00087', 'Amoxicillin 250mg Capsule', 'STRIP', 88.0],
            ],
        );

        $this->actingAs($user)->post('/api/stock-imports', ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated();

        // The product already known is refreshed, not duplicated.
        $this->assertSame(2, Item::count());

        $existing = Item::where('product_code', 'MRAQ-00003')->firstOrFail();
        $this->assertSame('Paracetamol 500mg Tablet', $existing->description);
        $this->assertSame('STRIP', $existing->uom);
        $this->assertEquals(32.5, $existing->price);

        // And the new one is created.
        $this->assertNotNull(Item::where('product_code', 'MRAQ-00087')->first());
    }

    public function test_a_flat_single_sheet_file_still_requires_a_shop(): void
    {
        $user = $this->userWithRole();
        $this->shopFor('PHM001', 'P001');

        $spreadsheet = new Spreadsheet;
        $this->fill($spreadsheet->getActiveSheet(),
            ['Product Code', 'Product Description', 'System Stock'],
            [['MED-1001', 'Paracetamol 500mg Tablet', 40]]);

        $response = $this->actingAs($user)->post('/api/stock-imports', [
            'file' => $this->save($spreadsheet),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $this->assertStringContainsString('Choose the shop', $response->json('message'));
    }

    // ---------------------------------------------------------------- helpers

    private function shopFor(string $code, ?string $location): Shop
    {
        return Shop::create([
            'shop_code' => $code,
            'shop_name' => 'Pharmacy '.$code,
            'ax_location_id' => $location,
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<int, array<int, mixed>>  $stock
     * @param  array<int, array<int, mixed>>  $batches
     * @param  array<int, array<int, mixed>>  $items
     */
    private function stockReport(array $stock, array $batches, array $items): UploadedFile
    {
        $spreadsheet = new Spreadsheet;

        $this->fill(
            $spreadsheet->getActiveSheet()->setTitle('stock'),
            ['INVENTLOCATIONID', 'ITEMID', 'INVENTBATCHID', 'EXPDATE', 'LOWERQTY', 'HIGHERQTY', 'COSTPERINVUNIT'],
            $stock
        );

        $this->fill(
            $spreadsheet->createSheet()->setTitle('all batches'),
            ['ITEMID', 'INVENTBATCHID', 'EXPDATE', 'ITEMBARCODE'],
            $batches
        );

        $this->fill(
            $spreadsheet->createSheet()->setTitle('Item Master'),
            ['ITEMID', 'ITEMNAME', 'ITEMBUYERGROUPID', 'INVUNIT', 'COSTPRICE', 'PURCHSASEUNIT', 'PURCHASEPRICE', 'SALESUNIT', 'SALESPRICE'],
            array_map(fn ($i) => [$i[0], $i[1], null, $i[2], 0, $i[2], 0, $i[2], $i[3]], $items)
        );

        return $this->save($spreadsheet);
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

    private function save(Spreadsheet $spreadsheet, string $name = 'Stock report.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pv-report-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, $name, null, null, true);
    }
}
