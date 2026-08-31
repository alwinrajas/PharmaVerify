<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemStock;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Item Import and Stock Import are two separate operations.
 *
 * Item Import maintains the product list — run once to seed it, and again
 * whenever products are added or their details change. Stock Import loads the
 * ERP's quantity snapshot. Neither does the other's job.
 */
class ItemImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_item_import_creates_and_updates_products_without_touching_stock(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = $this->makeShop();
        $existing = $this->makeItem('MED-1001');

        ItemStock::create([
            'shop_id' => $shop->id,
            'item_id' => $existing->id,
            'product_code' => 'MED-1001',
            'description' => 'Paracetamol 500mg Tablet',
            'system_qty' => 100,
            'uom' => 'STRIP',
            'price' => 32.5,
            'batch' => 'B001',
        ]);

        $file = $this->itemFile([
            ['MED-1001', 'Paracetamol 500mg Tablet (revised)', 'STRIP', 41.0, '08901234500011'],
            ['MED-9001', 'Ibuprofen 400mg Tablet', 'STRIP', 55.25, '08901234599991'],
        ]);

        $response = $this->actingAs($user)
            ->post('/api/items/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertSame(1, $response->json('data.created'));
        $this->assertSame(1, $response->json('data.updated'));

        // The master moved.
        $updated = Item::where('product_code', 'MED-1001')->firstOrFail();
        $this->assertSame('Paracetamol 500mg Tablet (revised)', $updated->description);
        $this->assertEquals(41.0, $updated->price);
        $this->assertSame('08901234500011', $updated->gtin);

        $this->assertNotNull(Item::where('product_code', 'MED-9001')->first());

        // The stock snapshot did not.
        $stock = ItemStock::firstOrFail();
        $this->assertEquals(100, $stock->system_qty);
        $this->assertEquals(32.5, $stock->price);
        $this->assertSame(1, ItemStock::count());
    }

    /** Price is the retail selling price here too. */
    public function test_the_item_price_comes_from_salesprice(): void
    {
        $user = $this->userWithRole();

        $file = $this->itemFile([['MED-7001', 'Cetirizine 10mg Tablet', 'STRIP', 27.4, null]], costPrice: 8.15);

        $this->actingAs($user)
            ->post('/api/items/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated();

        $item = Item::where('product_code', 'MED-7001')->firstOrFail();

        $this->assertEquals(27.4, $item->price);
        $this->assertNotEquals(8.15, (float) $item->price);
    }

    /** An import may be limited to products the master has never seen. */
    public function test_existing_products_can_be_left_alone(): void
    {
        $user = $this->userWithRole();
        $this->makeItem('MED-1001');

        $file = $this->itemFile([
            ['MED-1001', 'Should not be applied', 'EA', 999.0, null],
            ['MED-9002', 'Brand new product', 'STRIP', 12.0, null],
        ]);

        $response = $this->actingAs($user)->post('/api/items/import', [
            'file' => $file,
            'update_existing' => '0',
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(1, $response->json('data.created'));
        $this->assertSame(0, $response->json('data.updated'));

        $this->assertSame('Paracetamol 500mg Tablet', Item::where('product_code', 'MED-1001')->value('description'));
        $this->assertNotNull(Item::where('product_code', 'MED-9002')->first());
    }

    /** An item file never removes a product, whatever it leaves out. */
    public function test_an_item_import_never_deletes_a_product(): void
    {
        $user = $this->userWithRole();
        $this->makeItem('MED-1001');
        $this->makeItem('MED-1002', '8901234500028');

        $file = $this->itemFile([['MED-1001', 'Paracetamol 500mg Tablet', 'STRIP', 32.5, null]]);

        $this->actingAs($user)
            ->post('/api/items/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertSame(2, Item::count());
        $this->assertNotNull(Item::where('product_code', 'MED-1002')->first());
    }

    public function test_a_user_without_the_item_permission_is_refused(): void
    {
        $user = $this->userWithRole(Roles::SHOP_USER);

        $this->actingAs($user)->post('/api/items/import', [
            'file' => $this->itemFile([['MED-1001', 'Paracetamol', 'STRIP', 32.5, null]]),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->assertSame(0, Item::count());
    }

    /** The full three-sheet Stock Report still reads its Item Master sheet. */
    public function test_the_item_master_sheet_is_read_out_of_a_multi_sheet_workbook(): void
    {
        $user = $this->userWithRole();

        $file = $this->workbookWithSheets([
            'stock' => ['INVENTLOCATIONID' => ['PHM001'], 'ITEMID' => ['MED-2001'], 'INVENTBATCHID' => ['B1'], 'LOWERQTY' => [10], 'TOTALCOST' => [100]],
            'all batches' => ['ITEMID' => ['MED-2001'], 'INVENTBATCHID' => ['B1'], 'ITEMBARCODE' => ['8901234500011']],
            'Item Master' => null,
        ], [['MED-2001', 'Amoxicillin 250mg Capsule', 'STRIP', 45.0, '8901234500011']]);

        $this->actingAs($user)
            ->post('/api/items/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertNotNull(Item::where('product_code', 'MED-2001')->first());
    }

    /**
     * A workbook of stock sheets is a stock file the user reached for on the
     * wrong screen. It must fail clearly, naming the sheets it actually found,
     * rather than trying to import stock columns as products.
     */
    public function test_a_workbook_without_an_item_master_sheet_fails_clearly_and_creates_nothing(): void
    {
        $user = $this->userWithRole();

        $file = $this->workbookWithSheets([
            'stock' => ['INVENTLOCATIONID' => ['PHM001'], 'ITEMID' => ['MED-3001'], 'INVENTBATCHID' => ['B1'], 'LOWERQTY' => [10], 'TOTALCOST' => [100]],
            'all batches' => ['ITEMID' => ['MED-3001'], 'INVENTBATCHID' => ['B1'], 'ITEMBARCODE' => ['8901234500099']],
        ], []);

        $response = $this->actingAs($user)
            ->post('/api/items/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(
            'The Item Master sheet was not found. This file has the sheets: stock, all batches. Items are imported from the Item Master sheet only; stock and batch data is imported separately from Item Stock Import.',
            $response->json('message')
        );

        $this->assertSame(0, Item::count());
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array<int, array<int, mixed>>  $items  [code, name, uom, salesPrice, gtin]
     */
    private function itemFile(array $items, float $costPrice = 0.0): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet()->setTitle('Item Master');

        $headings = [
            'ITEMID', 'ITEMNAME', 'ITEMBUYERGROUPID', 'INVUNIT', 'COSTPRICE',
            'PURCHSASEUNIT', 'PURCHASEPRICE', 'SALESUNIT', 'SALESPRICE',
            'CATEGORYNAME', 'SUBCATEGORYNAME', 'SUBCATEGORYID', 'SUPPLIERID',
            'FACTOR', 'GLOBALTRADEITEMNUMBER',
        ];

        foreach ($headings as $column => $heading) {
            $sheet->setCellValue([$column + 1, 1], $heading);
        }

        foreach ($items as $index => $item) {
            $row = [
                $item[0], $item[1], null, $item[2], $costPrice,
                $item[2], 0, $item[2], $item[3],
                null, null, null, null,
                1, $item[4] ?? null,
            ];

            foreach ($row as $column => $value) {
                $sheet->setCellValue([$column + 1, $index + 2], $value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'pv-items-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'Item master.xlsx', null, null, true);
    }

    /**
     * Builds a workbook with an arbitrary set of sheets, so the "sheet not
     * found" behaviour can be exercised against something that looks like a
     * real Stock Report or Stock Import export.
     *
     * @param  array<string, array<string, array<int, mixed>>|null>  $sheets  sheet name => [heading => values], or null for the standard Item Master sheet
     * @param  array<int, array<int, mixed>>  $itemRows  [code, name, uom, salesPrice, gtin], used for any null entry above
     */
    private function workbookWithSheets(array $sheets, array $itemRows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $first = true;

        foreach ($sheets as $name => $columns) {
            $sheet = $first ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $sheet->setTitle($name);
            $first = false;

            if ($columns === null) {
                $this->writeItemMasterSheet($sheet, $itemRows);

                continue;
            }

            $column = 1;
            foreach ($columns as $heading => $values) {
                $sheet->setCellValue([$column, 1], $heading);

                foreach ($values as $rowIndex => $value) {
                    $sheet->setCellValue([$column, $rowIndex + 2], $value);
                }

                $column++;
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'pv-workbook-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'Stock Report.xlsx', null, null, true);
    }

    /**
     * @param  array<int, array<int, mixed>>  $items  [code, name, uom, salesPrice, gtin]
     */
    private function writeItemMasterSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $items): void
    {
        $headings = [
            'ITEMID', 'ITEMNAME', 'ITEMBUYERGROUPID', 'INVUNIT', 'COSTPRICE',
            'PURCHSASEUNIT', 'PURCHASEPRICE', 'SALESUNIT', 'SALESPRICE',
            'CATEGORYNAME', 'SUBCATEGORYNAME', 'SUBCATEGORYID', 'SUPPLIERID',
            'FACTOR', 'GLOBALTRADEITEMNUMBER',
        ];

        foreach ($headings as $column => $heading) {
            $sheet->setCellValue([$column + 1, 1], $heading);
        }

        foreach ($items as $index => $item) {
            $row = [
                $item[0], $item[1], null, $item[2], 0,
                $item[2], 0, $item[2], $item[3],
                null, null, null, null,
                1, $item[4] ?? null,
            ];

            foreach ($row as $column => $value) {
                $sheet->setCellValue([$column + 1, $index + 2], $value);
            }
        }
    }
}
