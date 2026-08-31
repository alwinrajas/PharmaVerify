<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Shop;
use App\Models\StockImport;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
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

    /**
     * The item master and the stock snapshot are two separate operations.
     *
     * A stock file may introduce a product the master has never seen — the
     * stock line still needs something to point at — but it must not rewrite
     * the details of a product Item Import already maintains.
     */
    public function test_a_stock_import_creates_missing_products_but_does_not_rewrite_existing_ones(): void
    {
        $user = $this->userWithRole();
        $this->shopFor('PHM001', 'P001');

        Item::create([
            'product_code' => 'MRAQ-00003',
            'description' => 'Description maintained by Item Import',
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
                ['MRAQ-00003', 'B-1', 46023, '4500011'],
                ['MRAQ-00087', 'B-2', 46024, '4500028'],
            ],
            items: [
                ['MRAQ-00003', 'Paracetamol 500mg Tablet', 'STRIP', 32.5, 10, '08901234500011'],
                ['MRAQ-00087', 'Amoxicillin 250mg Capsule', 'STRIP', 88.0, 10, '08901234500028'],
            ],
        );

        $this->actingAs($user)->post('/api/stock-imports', ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertSame(2, Item::count());

        // Untouched: the product list belongs to Item Import.
        $existing = Item::where('product_code', 'MRAQ-00003')->firstOrFail();
        $this->assertSame('Description maintained by Item Import', $existing->description);
        $this->assertSame('EA', $existing->uom);
        $this->assertEquals(1, $existing->price);

        // Created, so the stock line has a product to belong to.
        $created = Item::where('product_code', 'MRAQ-00087')->firstOrFail();
        $this->assertSame('Amoxicillin 250mg Capsule', $created->description);
        $this->assertSame('08901234500028', $created->gtin);
    }

    /**
     * Two identifier systems, kept apart. The GTIN printed on the carton is
     * what the handheld scans; the 7-digit internal code is a separate thing
     * and must never be written into the GTIN's place.
     */
    public function test_the_gtin_is_the_scan_identifier_and_the_seven_digit_barcode_does_not_displace_it(): void
    {
        $user = $this->userWithRole();
        $shop = $this->shopFor('PHM001', 'P001');

        $file = $this->stockReport(
            stock: [['P001', 'MRAQ-00003', 'B-1', 46023, 12, 12, 5.5]],
            batches: [['MRAQ-00003', 'B-1', 46023, '4500011']],
            items: [['MRAQ-00003', 'Paracetamol 500mg Tablet', 'STRIP', 32.5, 10, '08901234500011']],
        );

        $this->actingAs($user)->post('/api/stock-imports', ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated();

        $line = ItemStock::where('shop_id', $shop->id)->firstOrFail();

        $this->assertSame('08901234500011', $line->gtin);
        $this->assertSame('4500011', $line->barcode);
        $this->assertNotSame($line->barcode, $line->gtin);

        // And the product carries the GTIN too, not the internal code.
        $this->assertSame('08901234500011', Item::where('product_code', 'MRAQ-00003')->value('gtin'));
    }

    /**
     * The export the business now sends has no "all batches" sheet at all —
     * just Stock and Item Master, with the GTIN in the item master doing all
     * the identifying. That file must import as a Stock Report, not be turned
     * away for missing a sheet the format no longer has.
     */
    public function test_a_two_sheet_report_without_the_batches_sheet_imports_on_the_gtin_alone(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = $this->shopFor('PHM001', 'P001');

        $file = $this->stockReport(
            stock: [
                ['P001', 'MRAQ-00003', 'B-1', 46023, 12, 12, 5.5],
                ['P001', 'MRAQ-00087', 'B-2', 46024, 7, 7, 3.25],
            ],
            batches: null,
            items: [
                ['MRAQ-00003', 'Paracetamol 500mg Tablet', 'STRIP', 32.5, 10, '3664798000344'],
                ['MRAQ-00087', 'Amoxicillin 250mg Capsule', 'STRIP', 88.0, 10, '8901234500028'],
            ],
        );

        $response = $this->actingAs($user)->post('/api/stock-imports', ['file' => $file], ['Accept' => 'application/json']);

        $response->assertCreated();
        $this->assertSame('stock_report', $response->json('meta.format'));
        $this->assertEquals(2, $response->json('meta.summary.imported'));

        $line = ItemStock::where('shop_id', $shop->id)->where('product_code', 'MRAQ-00003')->firstOrFail();

        // The GTIN is the scan identifier; with no batches sheet there is no
        // legacy 7-digit barcode, and nothing is invented to fill its place.
        $this->assertSame('3664798000344', $line->gtin);
        $this->assertNull($line->barcode);
        $this->assertEquals(12, $line->system_qty);
        $this->assertSame('Paracetamol 500mg Tablet', $line->description);
    }

    /** Without an Item Master sheet there is no report, two sheets or three. */
    public function test_a_two_sheet_workbook_still_needs_the_item_master(): void
    {
        $user = $this->userWithRole();
        $shop = $this->shopFor('PHM001', 'P001');
        $item = $this->makeItem();
        $this->makeStock($shop, $item, 100, 'KEEP');

        $spreadsheet = new Spreadsheet;
        $this->fill($spreadsheet->getActiveSheet()->setTitle('stock'),
            ['INVENTLOCATIONID', 'ITEMID', 'INVENTBATCHID', 'EXPDATE', 'LOWERQTY', 'TOTALCOST'],
            [['P001', 'MRAQ-00003', 'B-1', 46023, 12, 66]]);
        $this->fill($spreadsheet->createSheet()->setTitle('Notes'), ['REMARK'], [['x']]);

        $response = $this->actingAs($user)->post('/api/stock-imports', [
            'shop_id' => $shop->id,
            'file' => $this->save($spreadsheet),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $this->assertEquals(100, ItemStock::firstOrFail()->system_qty);
        $this->assertSame(0, StockImport::count());
    }

    /** Price is the retail selling price. COSTPRICE is never used in its place. */
    public function test_the_stock_price_comes_from_salesprice_not_costprice(): void
    {
        $user = $this->userWithRole();
        $shop = $this->shopFor('PHM001', 'P001');

        $file = $this->stockReport(
            stock: [['P001', 'MRAQ-00003', 'B-1', 46023, 12, 12, 5.5]],
            batches: [['MRAQ-00003', 'B-1', 46023, '4500011']],
            // salesPrice 32.5, factor 10, gtin, costPrice 9.75
            items: [['MRAQ-00003', 'Paracetamol 500mg Tablet', 'STRIP', 32.5, 10, '08901234500011', 9.75]],
        );

        $this->actingAs($user)->post('/api/stock-imports', ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated();

        $line = ItemStock::where('shop_id', $shop->id)->firstOrFail();

        $this->assertEquals(32.5, $line->price);
        $this->assertNotEquals(9.75, (float) $line->price);
        // Nor the per-unit cost from the stock sheet.
        $this->assertNotEquals(5.5, (float) $line->price);
    }

    /**
     * A part-pack holding is a real quantity. Whole quantity must keep its
     * decimals through import and storage — never rounded to a whole number.
     */
    public function test_a_fractional_whole_quantity_is_preserved(): void
    {
        $user = $this->userWithRole();
        $shop = $this->shopFor('PHM001', 'P001');

        $file = $this->stockReport(
            stock: [
                ['P001', 'FRAC-01', 'B-1', 46023, 4, 0.04, 5.5],
                ['P001', 'FRAC-02', 'B-2', 46024, 6, 0.24, 5.5],
                ['P001', 'FRAC-03', 'B-3', 46025, 9, 0.33, 5.5],
                ['P001', 'FRAC-04', 'B-4', 46026, 138, 1.38, 5.5],
            ],
            batches: [],
            items: [
                ['FRAC-01', 'Fraction one', 'STRIP', 10.0, 100],
                ['FRAC-02', 'Fraction two', 'STRIP', 10.0, 25],
                ['FRAC-03', 'Fraction three', 'STRIP', 10.0, 27],
                ['FRAC-04', 'Fraction four', 'STRIP', 10.0, 100],
            ],
        );

        $this->actingAs($user)->post('/api/stock-imports', ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated();

        $stock = ItemStock::where('shop_id', $shop->id)->pluck('whole_qty', 'product_code');

        $this->assertEquals(0.04, $stock['FRAC-01']);
        $this->assertEquals(0.24, $stock['FRAC-02']);
        $this->assertEquals(0.33, $stock['FRAC-03']);
        $this->assertEquals(1.38, $stock['FRAC-04']);
    }

    /**
     * Where the report omits HIGHERQTY, the confirmed relationship
     * `HIGHERQTY = LOWERQTY / FACTOR` fills it in — still without rounding.
     */
    public function test_whole_quantity_is_derived_from_the_factor_when_the_column_is_blank(): void
    {
        $user = $this->userWithRole();
        $shop = $this->shopFor('PHM001', 'P001');

        $file = $this->stockReport(
            // HIGHERQTY deliberately left blank.
            stock: [['P001', 'MRAQ-00003', 'B-1', 46023, 5, null, 5.5]],
            batches: [],
            items: [['MRAQ-00003', 'Paracetamol 500mg Tablet', 'STRIP', 32.5, 8]],
        );

        $this->actingAs($user)->post('/api/stock-imports', ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated();

        $line = ItemStock::where('shop_id', $shop->id)->firstOrFail();

        $this->assertEquals(8, $line->factor);
        $this->assertEquals(0.625, $line->whole_qty);
    }

    /**
     * The ERP total does not reconcile with quantity times unit cost, so it is
     * stored exactly as supplied rather than recomputed.
     */
    public function test_totalcost_is_stored_exactly_as_the_erp_supplied_it(): void
    {
        $user = $this->userWithRole();
        $shop = $this->shopFor('PHM001', 'P001');

        // 12 x 5.5 would be 66.00 — the ERP says 71.4321, and the ERP wins.
        $file = $this->stockReport(
            stock: [['P001', 'MRAQ-00003', 'B-1', 46023, 12, 1.2, 5.5, 55.0, 71.4321]],
            batches: [],
            items: [['MRAQ-00003', 'Paracetamol 500mg Tablet', 'STRIP', 32.5, 10]],
        );

        $this->actingAs($user)->post('/api/stock-imports', ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated();

        $line = ItemStock::where('shop_id', $shop->id)->firstOrFail();

        $this->assertEquals(71.4321, $line->total_cost);
        $this->assertNotEquals(66.0, (float) $line->total_cost);
    }

    /**
     * A GTIN two products answer to cannot resolve a scan. It is reported
     * rather than settled by a rule nobody has agreed.
     */
    public function test_a_gtin_shared_by_two_products_is_reported_as_a_data_issue(): void
    {
        $user = $this->userWithRole();
        $this->shopFor('PHM001', 'P001');

        $file = $this->stockReport(
            stock: [
                ['P001', 'DUP-A', 'B-1', 46023, 5, 0.5, 5.5],
                ['P001', 'DUP-B', 'B-2', 46024, 5, 0.5, 5.5],
                ['P001', 'SOLO', 'B-3', 46025, 5, 0.5, 5.5],
            ],
            batches: [],
            items: [
                ['DUP-A', 'Product A', 'STRIP', 10.0, 10, '08901234500011'],
                ['DUP-B', 'Product B', 'STRIP', 10.0, 10, '08901234500011'],
                ['SOLO', 'Product C', 'STRIP', 10.0, 10, null],
            ],
        );

        $response = $this->actingAs($user)
            ->post('/api/stock-imports', ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertSame(1, $response->json('meta.summary.gtin_duplicates'));
        $this->assertSame(1, $response->json('meta.summary.gtin_missing'));

        // The stock itself still imported — the ambiguity is reported, not fatal.
        $this->assertSame(3, ItemStock::count());
    }

    /** The preview shows what would be replaced, and writes nothing. */
    public function test_a_preview_reports_the_replacement_impact_without_changing_anything(): void
    {
        $user = $this->userWithRole();
        $one = $this->shopFor('PHM001', 'P001');
        $this->shopFor('PHM002', 'T033');

        ItemStock::create([
            'shop_id' => $one->id,
            'product_code' => 'OLD-1',
            'description' => 'Already here',
            'system_qty' => 5,
            'uom' => 'EA',
            'price' => 1,
            'batch' => 'B-OLD',
        ]);

        $file = $this->stockReport(
            stock: [
                ['P001', 'MRAQ-00003', 'B-1', 46023, 12, 1.2, 5.5],
                ['T033', 'MRAQ-00003', 'B-9', 46025, 4, 0.4, 5.5],
                ['ZZ99', 'MRAQ-00003', 'B-7', 46025, 4, 0.4, 5.5],
            ],
            batches: [],
            items: [['MRAQ-00003', 'Paracetamol 500mg Tablet', 'STRIP', 32.5, 10, '08901234500011']],
        );

        $response = $this->actingAs($user)
            ->post('/api/stock-imports/preview', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame(2, $response->json('data.valid_rows'));
        $this->assertSame(1, $response->json('data.invalid_rows'));
        $this->assertSame('ZZ99', $response->json('data.unmatched_locations.0.ax_location_id'));

        $shops = collect($response->json('data.shops'))->keyBy('ax_location_id');
        $this->assertSame(1, $shops['P001']['existing_records']);
        $this->assertSame(1, $shops['P001']['incoming_records']);
        $this->assertSame('replace', $shops['P001']['action']);
        $this->assertSame(0, $shops['T033']['existing_records']);

        // Nothing was written or removed.
        $this->assertSame(1, ItemStock::count());
        $this->assertSame('OLD-1', ItemStock::first()->product_code);
        $this->assertSame(0, StockImport::count());
    }


    /**
     * Two branches in one file, each replaced against its own warehouse code.
     *
     * The risk this guards is the worst one the import has: a row landing on
     * the wrong shop, or one shop's replacement taking another shop's stock
     * with it. So the assertions are per shop, on the rows themselves, and a
     * third shop is present purely to prove it was never touched.
     */
    public function test_each_warehouse_code_is_replaced_independently_of_the_others(): void
    {
        $user = $this->userWithRole();
        $p001 = $this->shopFor('PHM001', 'P001');
        $t033 = $this->shopFor('PHM002', 'T033');
        $untouched = $this->shopFor('PHM003', 'Z999');

        $item = $this->makeItem();
        $this->makeStock($p001, $item, 100, 'P001-OLD');
        $this->makeStock($t033, $item, 200, 'T033-OLD');
        $this->makeStock($untouched, $item, 300, 'KEEP');

        $file = $this->stockReport(
            stock: [
                ['P001', 'MRAQ-00003', 'P001-NEW-A', 46023, 11, 1.1, 5.5],
                ['P001', 'MRAQ-00087', 'P001-NEW-B', 46024, 12, 1.2, 5.5],
                ['T033', 'MRAQ-00003', 'T033-NEW-A', 46025, 21, 2.1, 5.5],
            ],
            batches: [],
            items: [
                ['MRAQ-00003', 'Paracetamol 500mg Tablet', 'STRIP', 32.5, 10, '08901234500011'],
                ['MRAQ-00087', 'Amoxicillin 250mg Capsule', 'STRIP', 88.0, 10, '08901234500028'],
            ],
        );

        $this->actingAs($user)->post('/api/stock-imports', ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated();

        // P001 holds exactly what the file gave it, and nothing it held before.
        $p001Batches = ItemStock::where('shop_id', $p001->id)->pluck('batch')->sort()->values()->all();
        $this->assertSame(['P001-NEW-A', 'P001-NEW-B'], $p001Batches);

        // T033 likewise — and it did not inherit any of P001's rows.
        $t033Batches = ItemStock::where('shop_id', $t033->id)->pluck('batch')->sort()->values()->all();
        $this->assertSame(['T033-NEW-A'], $t033Batches);

        // Neither shop's new rows leaked into the other.
        $this->assertSame(0, ItemStock::where('shop_id', $t033->id)->where('batch', 'like', 'P001-%')->count());
        $this->assertSame(0, ItemStock::where('shop_id', $p001->id)->where('batch', 'like', 'T033-%')->count());

        // A shop the file never mentioned is exactly as it was.
        $keep = ItemStock::where('shop_id', $untouched->id)->get();
        $this->assertCount(1, $keep);
        $this->assertSame('KEEP', $keep->first()->batch);
        $this->assertEquals(300, $keep->first()->system_qty);

        // One import record per shop the file actually covered.
        $this->assertSame(2, StockImport::count());
        $this->assertEqualsCanonicalizing(
            [$p001->id, $t033->id],
            StockImport::pluck('shop_id')->all()
        );
    }

    /**
     * A database failure after the old rows are gone must put them back.
     *
     * Validation failures are covered elsewhere; this is the harder case — the
     * file is good, the delete has already run, and the write then fails. The
     * insert is made to throw so the rollback is exercised for real rather
     * than assumed from the presence of a transaction.
     */
    public function test_a_database_failure_midway_restores_the_stock_that_was_there(): void
    {
        $user = $this->userWithRole();
        $shop = $this->shopFor('PHM001', 'P001');

        $item = $this->makeItem();
        $this->makeStock($shop, $item, 100, 'ORIGINAL-A');
        $this->makeStock($shop, $item, 250, 'ORIGINAL-B');

        $file = $this->stockReport(
            stock: [['P001', 'MRAQ-00003', 'B-NEW', 46023, 12, 1.2, 5.5]],
            batches: [],
            items: [['MRAQ-00003', 'Paracetamol 500mg Tablet', 'STRIP', 32.5, 10, '08901234500011']],
        );

        // Fail the write that follows the delete, inside the transaction.
        DB::beforeExecuting(function (string $query) {
            if (str_contains(strtolower($query), 'insert into') && str_contains(strtolower($query), 'item_stocks')) {
                throw new RuntimeException('Simulated database failure during the replacement.');
            }
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($user)->post('/api/stock-imports', ['file' => $file], ['Accept' => 'application/json']);
            $this->fail('The import should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Simulated database failure', $exception->getMessage());
        }

        // Both original rows are back, with their quantities.
        $rows = ItemStock::where('shop_id', $shop->id)->orderBy('batch')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['ORIGINAL-A', 'ORIGINAL-B'], $rows->pluck('batch')->all());
        $this->assertEquals(100, $rows->firstWhere('batch', 'ORIGINAL-A')->system_qty);
        $this->assertEquals(250, $rows->firstWhere('batch', 'ORIGINAL-B')->system_qty);

        // And nothing half-written was left behind.
        $this->assertSame(0, ItemStock::where('batch', 'B-NEW')->count());
        $this->assertSame(0, StockImport::count());
    }


    /**
     * A shop coded P001 is the shop the report calls P001.
     *
     * INVENTLOCATIONID *is* the shop identifier, so no separate mapping should
     * be needed for the ordinary case. Requiring one was pure friction: every
     * shop already carries the code the report names it with.
     */
    public function test_a_warehouse_code_matches_a_shop_by_its_code_without_any_mapping(): void
    {
        $user = $this->userWithRole();

        // No ax_location_id at all — just a shop coded the way the report names it.
        $shop = Shop::create([
            'shop_code' => 'P001',
            'shop_name' => 'Wellness Pharmacy',
            'status' => 'active',
        ]);

        $file = $this->stockReport(
            stock: [['P001', 'MRAQ-00003', 'B-1', 46023, 12, 1.2, 5.5]],
            batches: [],
            items: [['MRAQ-00003', 'Paracetamol 500mg Tablet', 'STRIP', 32.5, 10, '08901234500011']],
        );

        $this->actingAs($user)->post('/api/stock-imports', ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertSame(1, ItemStock::where('shop_id', $shop->id)->count());
    }

    /** Where the two genuinely differ, the explicit mapping wins. */
    public function test_an_ax_location_overrides_the_shop_code(): void
    {
        $user = $this->userWithRole();

        // This shop is coded PHM001 here but is warehouse P001 in the ERP.
        $mapped = $this->shopFor('PHM001', 'P001');
        // And this one is coded P001, which must not steal P001's rows.
        $decoy = Shop::create(['shop_code' => 'P001', 'shop_name' => 'Decoy', 'status' => 'active']);

        $file = $this->stockReport(
            stock: [['P001', 'MRAQ-00003', 'B-1', 46023, 12, 1.2, 5.5]],
            batches: [],
            items: [['MRAQ-00003', 'Paracetamol 500mg Tablet', 'STRIP', 32.5, 10, '08901234500011']],
        );

        $this->actingAs($user)->post('/api/stock-imports', ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertSame(1, ItemStock::where('shop_id', $mapped->id)->count());
        $this->assertSame(0, ItemStock::where('shop_id', $decoy->id)->count());
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
     * A workbook shaped like the real export.
     *
     * `stock`  => [location, item, batch, expiry, lowerQty, higherQty, costPerInvUnit, costPerPurUnit, totalCost]
     * `batches`=> [item, batch, expiry, itemBarcode]        — the 7-digit internal code
     * `items`  => [item, name, uom, salesPrice, factor, gtin, costPrice]
     *
     * Anything past the fourth value is optional; the defaults keep the older
     * cases readable while still producing every column the mapping requires.
     *
     * @param  array<int, array<int, mixed>>  $stock
     * @param  array<int, array<int, mixed>>  $batches
     * @param  array<int, array<int, mixed>>  $items
     */
    private function stockReport(array $stock, ?array $batches, array $items): UploadedFile
    {
        $spreadsheet = new Spreadsheet;

        $this->fill(
            $spreadsheet->getActiveSheet()->setTitle('stock'),
            [
                'INVENTLOCATIONID', 'ITEMID', 'INVENTBATCHID', 'EXPDATE',
                'LOWERQTY', 'HIGHERQTY', 'COSTPERINVUNIT', 'COSTPERPURUNIT', 'TOTALCOST',
            ],
            array_map(fn (array $r) => [
                $r[0], $r[1], $r[2], $r[3], $r[4],
                $r[5] ?? null,
                $r[6] ?? null,
                $r[7] ?? null,
                // A plausible total where the case does not care about it.
                $r[8] ?? round(((float) ($r[4] ?? 0)) * ((float) ($r[6] ?? 0)), 4),
            ], $stock)
        );

        if ($batches !== null) {
            $this->fill(
                $spreadsheet->createSheet()->setTitle('all batches'),
                ['ITEMID', 'INVENTBATCHID', 'EXPDATE', 'ITEMBARCODE'],
                $batches
            );
        }

        $this->fill(
            $spreadsheet->createSheet()->setTitle('Item Master'),
            [
                'ITEMID', 'ITEMNAME', 'ITEMBUYERGROUPID', 'INVUNIT', 'COSTPRICE',
                'PURCHSASEUNIT', 'PURCHASEPRICE', 'SALESUNIT', 'SALESPRICE',
                'CATEGORYNAME', 'SUBCATEGORYNAME', 'SUBCATEGORYID', 'SUPPLIERID',
                'FACTOR', 'GLOBALTRADEITEMNUMBER',
            ],
            array_map(fn (array $i) => [
                $i[0], $i[1], null, $i[2],
                $i[6] ?? 0,
                $i[2], 0, $i[2], $i[3],
                null, null, null, null,
                $i[4] ?? 1,
                $i[5] ?? null,
            ], $items)
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
