<?php

namespace Tests\Feature;

use App\Models\ItemStock;
use App\Models\StockImport;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class StockImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_importing_a_file_replaces_the_shops_existing_stock(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = $this->makeShop();
        $item = $this->makeItem();

        // Stock the shop already holds, which the import must remove.
        $this->makeStock($shop, $item, 100, 'OLD-BATCH');
        $this->assertSame(1, ItemStock::where('shop_id', $shop->id)->count());

        $file = $this->makeWorkbook([
            ['Product Code', 'Barcode', 'Product Description', 'System Stock', 'UOM', 'Price', 'Batch', 'Expiry Date', 'Shelf'],
            ['MED-1001', '8901234500011', 'Paracetamol 500mg Tablet', 150, 'STRIP', 32.5, 'NEW-001', '2027-06-30', 'A-01'],
            ['MED-1002', '8901234500028', 'Amoxicillin 250mg Capsule', 80, 'STRIP', 88.0, 'NEW-002', '2027-09-30', 'A-02'],
        ]);

        $response = $this->actingAs($user)->post('/api/stock-imports', [
            'shop_id' => $shop->id,
            'file' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertCreated();

        $stock = ItemStock::where('shop_id', $shop->id)->get();

        $this->assertCount(2, $stock, 'The import replaces rather than appends.');
        $this->assertSame(0, $stock->where('batch', 'OLD-BATCH')->count(), 'The previous stock must be gone.');
        $this->assertEquals(150, $stock->firstWhere('product_code', 'MED-1001')->system_qty);

        $import = StockImport::firstOrFail();
        $this->assertSame(2, $import->total_records);
        $this->assertSame(2, $import->success_records);
        $this->assertSame(0, $import->failed_records);
        $this->assertSame(1, $import->replaced_records);
        $this->assertSame('completed', $import->status);
    }

    public function test_stock_belonging_to_another_shop_is_untouched(): void
    {
        $user = $this->userWithRole();
        $shopOne = $this->makeShop('PHM001');
        $shopTwo = $this->makeShop('PHM002');
        $item = $this->makeItem();

        $this->makeStock($shopOne, $item, 100, 'B001');
        $this->makeStock($shopTwo, $item, 200, 'B002');

        $file = $this->makeWorkbook([
            ['Product Code', 'Product Description', 'System Stock'],
            ['MED-1001', 'Paracetamol 500mg Tablet', 55],
        ]);

        $this->actingAs($user)->post('/api/stock-imports', [
            'shop_id' => $shopOne->id,
            'file' => $file,
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(1, ItemStock::where('shop_id', $shopOne->id)->count());
        $this->assertEquals(200, ItemStock::where('shop_id', $shopTwo->id)->firstOrFail()->system_qty);
    }

    public function test_invalid_rows_are_reported_and_the_valid_rows_still_import(): void
    {
        $user = $this->userWithRole();
        $shop = $this->makeShop();

        $file = $this->makeWorkbook([
            ['Product Code', 'Product Description', 'System Stock', 'Batch'],
            ['MED-1001', 'Paracetamol 500mg Tablet', 40, 'B001'],
            ['MED-1002', 'Amoxicillin 250mg Capsule', 'not-a-number', 'B002'],
            ['', 'Missing product code', 10, 'B003'],
            ['MED-1004', 'Negative quantity', -5, 'B004'],
            ['MED-1001', 'Duplicate of row 2', 12, 'B001'],
        ]);

        $response = $this->actingAs($user)->post('/api/stock-imports', [
            'shop_id' => $shop->id,
            'file' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertCreated();

        $import = StockImport::firstOrFail();

        $this->assertSame(5, $import->total_records);
        $this->assertSame(1, $import->success_records);
        $this->assertSame(4, $import->failed_records);
        $this->assertSame('completed_with_errors', $import->status);

        $errors = $import->errors()->pluck('error_message');

        $this->assertTrue($errors->contains(fn ($message) => str_contains($message, 'must be a number')));
        $this->assertTrue($errors->contains(fn ($message) => str_contains($message, 'Product code is required')));
        $this->assertTrue($errors->contains(fn ($message) => str_contains($message, 'cannot be negative')));
        $this->assertTrue($errors->contains(fn ($message) => str_contains($message, 'Duplicate')));
    }

    public function test_a_file_missing_a_required_column_changes_nothing(): void
    {
        $user = $this->userWithRole();
        $shop = $this->makeShop();
        $item = $this->makeItem();
        $this->makeStock($shop, $item, 100, 'KEEP-ME');

        $file = $this->makeWorkbook([
            ['Product Code', 'Product Description'],
            ['MED-1001', 'Paracetamol 500mg Tablet'],
        ]);

        $response = $this->actingAs($user)->post('/api/stock-imports', [
            'shop_id' => $shop->id,
            'file' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $this->assertStringContainsString('System Stock', $response->json('message'));

        // The previous stock is still exactly as it was.
        $this->assertSame(1, ItemStock::where('shop_id', $shop->id)->count());
        $this->assertEquals(100, ItemStock::firstOrFail()->system_qty);
        $this->assertSame(0, StockImport::count());
    }

    public function test_a_file_where_every_row_is_invalid_leaves_the_existing_stock_intact(): void
    {
        $user = $this->userWithRole();
        $shop = $this->makeShop();
        $item = $this->makeItem();
        $this->makeStock($shop, $item, 100, 'KEEP-ME');

        $file = $this->makeWorkbook([
            ['Product Code', 'Product Description', 'System Stock'],
            ['', 'No product code', 10],
            ['MED-1002', 'Bad quantity', 'abc'],
        ]);

        $this->actingAs($user)->post('/api/stock-imports', [
            'shop_id' => $shop->id,
            'file' => $file,
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(1, ItemStock::where('shop_id', $shop->id)->count());
        $this->assertEquals(100, ItemStock::firstOrFail()->system_qty);
    }

    public function test_a_user_without_the_import_permission_is_refused(): void
    {
        $user = $this->userWithRole(Roles::SHOP_USER);
        $shop = $this->makeShop();
        $user->shops()->attach($shop->id);

        $file = $this->makeWorkbook([
            ['Product Code', 'Product Description', 'System Stock'],
            ['MED-1001', 'Paracetamol 500mg Tablet', 10],
        ]);

        $this->actingAs($user)->post('/api/stock-imports', [
            'shop_id' => $shop->id,
            'file' => $file,
        ], ['Accept' => 'application/json'])->assertStatus(403);

        $this->assertSame(0, StockImport::count());
    }

    public function test_header_wording_is_matched_flexibly(): void
    {
        $user = $this->userWithRole();
        $shop = $this->makeShop();

        // "Item Code", "Description" and "Closing Stock" mean the same thing.
        $file = $this->makeWorkbook([
            ['Item Code', 'Description', 'Closing Stock', 'Lot No', 'MRP'],
            ['MED-1001', 'Paracetamol 500mg Tablet', 64, 'L-77', 32.5],
        ]);

        $this->actingAs($user)->post('/api/stock-imports', [
            'shop_id' => $shop->id,
            'file' => $file,
        ], ['Accept' => 'application/json'])->assertCreated();

        $stock = ItemStock::firstOrFail();

        $this->assertEquals(64, $stock->system_qty);
        $this->assertSame('L-77', $stock->batch);
        $this->assertEquals(32.5, $stock->price);
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function makeWorkbook(array $rows, string $name = 'stock.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValue([$columnIndex + 1, $rowIndex + 1], $value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'pv-test-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, $name, null, null, true);
    }
}
