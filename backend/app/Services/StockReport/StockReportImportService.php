<?php

namespace App\Services\StockReport;

use App\Exceptions\BusinessRuleException;
use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Shop;
use App\Models\StockImport;
use App\Models\StockImportError;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Imports the business Stock Report.
 *
 * The report is a Dynamics AX export of three sheets that have to be joined:
 * `stock` carries the quantities, `Item Master` the product details and
 * `all batches` the barcodes. One workbook covers every branch it was run for,
 * so a single upload replaces the stock of each shop it mentions.
 *
 * Order of work, chosen to keep memory flat and the database untouched until
 * the whole file has been judged:
 *
 *   1. Validate the sheets and their columns.
 *   2. Read `stock`, validating each row and noting which products and batches
 *      are actually referenced.
 *   3. Stream the two much larger sheets, keeping only those referenced keys.
 *   4. In one transaction: sync the item master, replace the stock of every
 *      shop in the file, and record the outcome.
 */
class StockReportImportService
{
    /** Rows written per insert. */
    private const INSERT_CHUNK = 1000;

    public function __construct(private readonly StockReportReader $reader) {}

    /**
     * @return array{imports: array<int, StockImport>, summary: array<string, mixed>}
     */
    public function import(UploadedFile $file, User $user, ?Shop $onlyShop = null): array
    {
        $storedPath = $file->store('imports');
        $path = $file->getRealPath() ?: storage_path('app/'.$storedPath);

        $sheets = $this->reader->assertStructure($path);

        $shops = $this->shopsByLocation();

        if ($shops === []) {
            throw new BusinessRuleException(
                'There are no shops to import against yet. Create the shops first — a shop coded P001 will match the '
                .'report rows for warehouse P001 automatically.'
            );
        }

        [$rows, $errors, $needed] = $this->readStockSheet($path, $sheets[StockReportReader::SHEET_STOCK], $shops, $onlyShop);

        if ($rows === []) {
            throw new BusinessRuleException(sprintf(
                'Stock import could not be completed. None of the %d row(s) in the report could be used, so the existing stock has been left unchanged.',
                count($errors)
            ));
        }

        $barcodes = $this->readBarcodes($path, $sheets[StockReportReader::SHEET_BATCHES], $needed['batches']);
        $products = $this->readItemMaster($path, $sheets[StockReportReader::SHEET_ITEMS], $needed['items']);

        return $this->persist($file, $storedPath, $user, $rows, $errors, $barcodes, $products);
    }

    /**
     * Reads and judges the report without writing anything.
     *
     * Replacing a shop's stock cannot be undone from the screen, so the user is
     * shown what the file contains and what it would displace before being
     * asked to confirm. This runs exactly the same reading and validation as
     * the import itself; only the transaction is missing.
     *
     * @return array<string, mixed>
     */
    public function preview(UploadedFile $file, ?Shop $onlyShop = null): array
    {
        $path = $file->getRealPath();

        $sheets = $this->reader->assertStructure($path);
        $shops = $this->shopsByLocation();

        if ($shops === []) {
            throw new BusinessRuleException(
                'There are no shops to import against yet. Create the shops first — a shop coded P001 will match the '
                .'report rows for warehouse P001 automatically.'
            );
        }

        [$rows, $errors, $needed] = $this->readStockSheet($path, $sheets[StockReportReader::SHEET_STOCK], $shops, $onlyShop);
        $products = $this->readItemMaster($path, $sheets[StockReportReader::SHEET_ITEMS], $needed['items']);

        // Every warehouse code the file mentioned that no shop answers to. The
        // rows are counted rather than dropped, so nothing disappears quietly.
        $unmatched = [];

        foreach ($errors as $error) {
            if ($error['column_name'] === 'INVENTLOCATIONID' && $error['column_value'] !== null) {
                $code = $error['column_value'];
                $unmatched[$code] = ($unmatched[$code] ?? 0) + 1;
            }
        }

        $shopsById = [];

        foreach ($shops as $shop) {
            $shopsById[$shop->id] = $shop;
        }

        $existingCounts = ItemStock::selectRaw('shop_id, count(*) as total')
            ->whereIn('shop_id', array_unique(array_column($rows, 'shop_id')))
            ->groupBy('shop_id')
            ->pluck('total', 'shop_id');

        $perShop = [];

        foreach ($rows as $row) {
            $perShop[$row['shop_id']] = ($perShop[$row['shop_id']] ?? 0) + 1;
        }

        $shopImpact = [];

        foreach ($perShop as $shopId => $incoming) {
            $shop = $shopsById[$shopId] ?? null;

            $shopImpact[] = [
                'shop_id' => $shopId,
                'shop_code' => $shop?->shop_code,
                'shop_name' => $shop?->shop_name,
                'ax_location_id' => $shop?->ax_location_id,
                'existing_records' => (int) ($existingCounts[$shopId] ?? 0),
                'incoming_records' => $incoming,
                'action' => 'replace',
            ];
        }

        $missingGtin = count(array_filter($products, fn ($p) => ($p['gtin'] ?? null) === null));
        $duplicates = $this->duplicateGtins($products);

        return [
            'file_name' => $file->getClientOriginalName(),
            'total_rows' => count($rows) + count($errors),
            'valid_rows' => count($rows),
            'invalid_rows' => count($errors),
            'locations_detected' => count($perShop) + count($unmatched),
            'shops' => $shopImpact,
            'unmatched_locations' => array_map(
                fn ($code, $count) => ['ax_location_id' => $code, 'rows' => $count],
                array_keys($unmatched),
                array_values($unmatched)
            ),
            'items_referenced' => count($needed['items']),
            'items_matched' => count($products),
            'items_unmatched' => count($needed['items']) - count($products),
            'gtin_missing' => $missingGtin,
            'gtin_duplicates' => array_map(
                fn ($gtin, $codes) => ['gtin' => $gtin, 'product_codes' => $codes],
                array_keys($duplicates),
                array_values($duplicates)
            ),
            // The first few reasons, so the screen can show why rows failed
            // without shipping the whole list to the browser.
            'sample_errors' => array_slice($errors, 0, 20),
        ];
    }

    /**
     * Shops keyed by every code a report might name them with.
     *
     * INVENTLOCATIONID *is* the shop identifier, so it is matched against what
     * the shop is already called before anything else. `ax_location_id` is the
     * explicit override for the case where the warehouse code genuinely differs
     * from the shop code, and it wins where it is set; `shop_code` covers the
     * ordinary case, where a shop coded P001 is the shop the report calls P001
     * and no one should have to say so twice.
     *
     * Nothing is created here. A code no shop answers to is reported, never
     * turned into a new branch as a side effect of an import.
     *
     * @return array<string, Shop>
     */
    private function shopsByLocation(): array
    {
        $shops = [];

        foreach (Shop::all() as $shop) {
            $code = strtoupper(trim((string) $shop->shop_code));

            if ($code !== '') {
                $shops[$code] = $shop;
            }
        }

        // Applied second so an explicit mapping overrides the shop code.
        foreach (Shop::whereNotNull('ax_location_id')->get() as $shop) {
            $location = strtoupper(trim((string) $shop->ax_location_id));

            if ($location !== '') {
                $shops[$location] = $shop;
            }
        }

        return $shops;
    }

    /**
     * Reads and validates the stock sheet.
     *
     * @param  array<string, Shop>  $shops
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array{items: array<string, true>, batches: array<string, true>}}
     */
    private function readStockSheet(string $path, string $sheetName, array $shops, ?Shop $onlyShop): array
    {
        $headings = $this->reader->headings($path, $sheetName);

        $at = fn (string $heading) => $headings[$heading] ?? null;

        $locationAt = $at('inventlocationid');
        $itemAt = $at('itemid');
        $batchAt = $at('inventbatchid');
        $expiryAt = $at('expdate');
        // PENDING (Q5): which column is the ERP system quantity is not yet
        // confirmed, so it is named in config rather than written in here. The
        // answer, when it comes, is a one-value change — see config/stockreport.php.
        $systemQtyColumn = (string) config('stockreport.system_quantity_column', 'lowerqty');
        $qtyAt = $at($systemQtyColumn) ?? $at('lowerqty');
        $wholeAt = $at('higherqty');
        $costAt = $at('costperinvunit');
        $totalCostAt = $at('totalcost');

        $columns = StockReportReader::columnLetters(max(array_values($headings)) + 1);

        $rows = [];
        $errors = [];
        $seen = [];
        $needed = ['items' => [], 'batches' => []];

        $this->reader->eachRowChunked($path, $sheetName, $columns, function (array $cells, int $rowNumber) use (
            &$rows, &$errors, &$seen, &$needed, $shops, $onlyShop,
            $locationAt, $itemAt, $batchAt, $expiryAt, $qtyAt, $wholeAt, $costAt, $totalCostAt
        ) {
            $location = strtoupper($this->text($cells[$locationAt] ?? null));
            $itemId = $this->text($cells[$itemAt] ?? null);
            $batch = $this->text($cells[$batchAt] ?? null);
            $rawQty = $cells[$qtyAt] ?? null;

            // A blank line at the end of a sheet is not an error.
            if ($location === '' && $itemId === '' && ($rawQty === null || $rawQty === '')) {
                return;
            }

            if ($location === '') {
                $errors[] = $this->error($rowNumber, 'INVENTLOCATIONID', null, 'The warehouse code is missing, so the row cannot be assigned to a shop.');

                return;
            }

            $shop = $shops[$location] ?? null;

            if (! $shop) {
                $errors[] = $this->error($rowNumber, 'INVENTLOCATIONID', $location, sprintf(
                    'No shop is linked to warehouse code %s. Set the AX location on the shop, then import again.',
                    $location
                ));

                return;
            }

            if ($onlyShop && $shop->id !== $onlyShop->id) {
                return;
            }

            if ($itemId === '') {
                $errors[] = $this->error($rowNumber, 'ITEMID', null, 'The item code is required.');

                return;
            }

            if ($rawQty === null || $rawQty === '' || ! is_numeric($rawQty)) {
                $errors[] = $this->error($rowNumber, 'LOWERQTY', $this->text($rawQty), 'The quantity must be a number.');

                return;
            }

            if ((float) $rawQty < 0) {
                $errors[] = $this->error($rowNumber, 'LOWERQTY', $this->text($rawQty), 'The quantity cannot be negative.');

                return;
            }

            $expiry = $this->parseExcelDate($cells[$expiryAt] ?? null);

            if ($expiryAt !== null && ($cells[$expiryAt] ?? null) !== null && ($cells[$expiryAt] ?? '') !== '' && $expiry === null) {
                $errors[] = $this->error($rowNumber, 'EXPDATE', $this->text($cells[$expiryAt] ?? null), 'The expiry date could not be read.');

                return;
            }

            // Stock identity is shop + product + batch, so a repeat within one
            // file is a duplicate rather than a second holding.
            $key = $shop->id.'|'.$itemId.'|'.$batch;

            if (isset($seen[$key])) {
                $errors[] = $this->error($rowNumber, 'ITEMID', $itemId, sprintf(
                    'Duplicate of row %d — the same shop, item and batch appears twice in the report.',
                    $seen[$key]
                ));

                return;
            }

            $seen[$key] = $rowNumber;
            $needed['items'][$itemId] = true;
            $needed['batches'][$itemId.'|'.$batch] = true;

            $cost = $costAt !== null && is_numeric($cells[$costAt] ?? null) ? (float) $cells[$costAt] : null;

            // The ERP's own whole-pack figure. Fractional values are expected
            // and correct — 0.04 of a pack is a third of a strip, not a fault
            // — so it is never rounded to a whole number. Where the column is
            // absent it is derived later from LOWERQTY / FACTOR.
            $rawWhole = $wholeAt !== null ? ($cells[$wholeAt] ?? null) : null;
            $wholeQty = is_numeric($rawWhole) ? round((float) $rawWhole, 4) : null;

            // Stored exactly as supplied. The ERP total does not reconcile
            // with quantity x unit cost across the whole report, so deriving it
            // would silently contradict the source system.
            $rawTotalCost = $totalCostAt !== null ? ($cells[$totalCostAt] ?? null) : null;
            $totalCost = is_numeric($rawTotalCost) ? round((float) $rawTotalCost, 4) : null;

            $rows[] = [
                'shop_id' => $shop->id,
                'product_code' => $itemId,
                'batch' => $batch,
                // PENDING (Q5). The column this reads is named in
                // config/stockreport.php and defaults to LOWERQTY, unchanged.
                'system_qty' => round((float) $rawQty, 3),
                'whole_qty' => $wholeQty,
                'expiry_date' => $expiry,
                'cost' => $cost,
                'total_cost' => $totalCost,
            ];
        });

        return [$rows, $errors, $needed];
    }

    /**
     * Barcodes for the item/batch pairs the stock sheet referenced.
     *
     * @param  array<string, true>  $wanted
     * @return array<string, string>
     */
    private function readBarcodes(string $path, string $sheetName, array $wanted): array
    {
        $headings = $this->reader->headings($path, $sheetName);
        $itemAt = $headings['itemid'] ?? 0;
        $batchAt = $headings['inventbatchid'] ?? 1;
        $barcodeAt = $headings['itembarcode'] ?? 3;

        $columns = StockReportReader::columnLetters(max($itemAt, $batchAt, $barcodeAt) + 1);
        $found = [];

        $this->reader->eachRowChunked($path, $sheetName, $columns, function (array $cells) use (
            &$found, $wanted, $itemAt, $batchAt, $barcodeAt
        ) {
            $key = $this->text($cells[$itemAt] ?? null).'|'.$this->text($cells[$batchAt] ?? null);

            if (! isset($wanted[$key]) || isset($found[$key])) {
                return;
            }

            $barcode = $this->text($cells[$barcodeAt] ?? null);

            if ($barcode !== '') {
                $found[$key] = mb_substr($barcode, 0, 60);
            }
        });

        return $found;
    }

    /**
     * Product details for the items the stock sheet referenced.
     *
     * Price comes from SALESPRICE and nothing else. The business has confirmed
     * that the price a stock line carries is the retail selling price, so the
     * old fallback to COSTPRICE is gone — a product with no selling price now
     * reads as zero rather than quietly showing a cost figure as if it were a
     * price. The cost columns remain available for the cost total, which is a
     * separate figure.
     *
     * @param  array<string, true>  $wanted
     * @return array<string, array{description: string, uom: string, price: float, factor: ?float, gtin: ?string}>
     */
    private function readItemMaster(string $path, string $sheetName, array $wanted): array
    {
        $headings = $this->reader->headings($path, $sheetName);
        $itemAt = $headings['itemid'] ?? 0;
        $nameAt = $headings['itemname'] ?? 1;
        $unitAt = $headings['invunit'] ?? null;
        $priceAt = $headings['salesprice'] ?? null;
        $factorAt = $headings['factor'] ?? null;
        $gtinAt = $headings['globaltradeitemnumber'] ?? null;

        $positions = array_filter([$itemAt, $nameAt, $unitAt, $priceAt, $factorAt, $gtinAt], fn ($p) => $p !== null);
        $columns = StockReportReader::columnLetters(max($positions) + 1);

        $found = [];

        $this->reader->eachRowChunked($path, $sheetName, $columns, function (array $cells) use (
            &$found, $wanted, $itemAt, $nameAt, $unitAt, $priceAt, $factorAt, $gtinAt
        ) {
            $id = $this->text($cells[$itemAt] ?? null);

            if ($id === '' || ! isset($wanted[$id]) || isset($found[$id])) {
                return;
            }

            $price = $priceAt !== null ? ($cells[$priceAt] ?? null) : null;
            $factor = $factorAt !== null ? ($cells[$factorAt] ?? null) : null;
            $gtin = $gtinAt !== null ? $this->text($cells[$gtinAt] ?? null) : '';

            $found[$id] = [
                'description' => mb_substr($this->text($cells[$nameAt] ?? null) ?: $id, 0, 300),
                'uom' => mb_substr($unitAt !== null ? ($this->text($cells[$unitAt] ?? null) ?: 'EA') : 'EA', 0, 20),
                'price' => is_numeric($price) ? round((float) $price, 4) : 0.0,
                // A factor of zero would make the whole-quantity division
                // meaningless, so it is treated as absent rather than used.
                'factor' => is_numeric($factor) && (float) $factor != 0.0 ? round((float) $factor, 4) : null,
                'gtin' => $gtin !== '' ? mb_substr($gtin, 0, 20) : null,
            ];
        });

        return $found;
    }

    /**
     * GTINs that more than one product answers to.
     *
     * The GTIN is what the handheld scans, so a code shared by two products
     * cannot be resolved to a single stock line. Rather than invent a rule for
     * picking a winner, the affected products are reported as a data issue and
     * the import continues — the stock is still correct, but the ambiguity is
     * on the record.
     *
     * @param  array<string, array<string, mixed>>  $products
     * @return array<string, array<int, string>>  GTIN => product codes
     */
    private function duplicateGtins(array $products): array
    {
        $byGtin = [];

        foreach ($products as $code => $product) {
            $gtin = $product['gtin'] ?? null;

            if ($gtin !== null && $gtin !== '') {
                $byGtin[$gtin][] = $code;
            }
        }

        return array_filter($byGtin, fn (array $codes) => count($codes) > 1);
    }

    /**
     * Writes the result. Everything here happens together or not at all, so a
     * failure part way through leaves each shop with the stock it already had.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $errors
     * @param  array<string, string>  $barcodes
     * @param  array<string, array<string, mixed>>  $products
     * @return array{imports: array<int, StockImport>, summary: array<string, mixed>}
     */
    private function persist(
        UploadedFile $file,
        string $storedPath,
        User $user,
        array $rows,
        array $errors,
        array $barcodes,
        array $products
    ): array {
        $shopIds = array_values(array_unique(array_column($rows, 'shop_id')));
        $now = now();

        return DB::transaction(function () use (
            $file, $storedPath, $user, $rows, $errors, $barcodes, $products, $shopIds, $now
        ) {
            $createdItems = $this->syncItemMaster($products, $barcodes, $user, $now);
            $itemIds = Item::whereIn('product_code', array_keys($products))->pluck('id', 'product_code');

            $imports = [];
            $replacedTotal = 0;

            foreach ($shopIds as $shopId) {
                $shopRows = array_values(array_filter($rows, fn ($r) => $r['shop_id'] === $shopId));
                $shopErrors = [];

                $import = StockImport::create([
                    'shop_id' => $shopId,
                    'file_name' => $file->getClientOriginalName(),
                    'stored_path' => $storedPath,
                    'total_records' => count($shopRows) + count($shopErrors),
                    'success_records' => 0,
                    'failed_records' => 0,
                    'status' => 'processing',
                    'imported_by' => $user->id,
                    'imported_at' => $now,
                ]);

                // Replace, never append: whatever this shop held is removed and
                // the report takes its place.
                $replaced = ItemStock::where('shop_id', $shopId)->count();
                ItemStock::where('shop_id', $shopId)->delete();
                $replacedTotal += $replaced;

                $buffer = [];
                $inserted = 0;

                foreach ($shopRows as $row) {
                    $product = $products[$row['product_code']] ?? null;
                    $factor = $product['factor'] ?? null;

                    $buffer[] = [
                        'shop_id' => $shopId,
                        'item_id' => $itemIds[$row['product_code']] ?? null,
                        'stock_import_id' => $import->id,
                        'product_code' => $row['product_code'],
                        // Two separate identifier systems, kept apart. The
                        // 7-digit internal code stays on `barcode`; the GTIN
                        // the handheld scans has its own column and is never
                        // substituted by the other.
                        'barcode' => $barcodes[$row['product_code'].'|'.$row['batch']] ?? null,
                        'gtin' => $product['gtin'] ?? null,
                        'description' => $product['description'] ?? $row['product_code'],
                        'system_qty' => $row['system_qty'],
                        'whole_qty' => $this->wholeQuantity($row, $factor),
                        'factor' => $factor,
                        'uom' => $product['uom'] ?? 'EA',
                        // Retail selling price, from SALESPRICE only.
                        'price' => (float) ($product['price'] ?? 0),
                        'total_cost' => $row['total_cost'],
                        'batch' => $row['batch'],
                        'expiry_date' => $row['expiry_date'],
                        'shelf_location' => null,
                        'verification_status' => 'not_verified',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($buffer) === self::INSERT_CHUNK) {
                        ItemStock::insert($buffer);
                        $inserted += count($buffer);
                        $buffer = [];
                    }
                }

                if ($buffer !== []) {
                    ItemStock::insert($buffer);
                    $inserted += count($buffer);
                }

                $import->update([
                    'success_records' => $inserted,
                    'replaced_records' => $replaced,
                    'status' => 'completed',
                ]);

                $imports[] = $import;
            }

            // Rejected rows belong to the report as a whole; they are recorded
            // against the first import so the user can still read them.
            if ($errors !== [] && $imports !== []) {
                $primary = $imports[0];

                foreach (array_chunk($errors, 500) as $chunk) {
                    StockImportError::insert(array_map(fn ($e) => [
                        'stock_import_id' => $primary->id,
                        'row_number' => $e['row_number'],
                        'column_name' => $e['column_name'],
                        'column_value' => $e['column_value'],
                        'error_message' => $e['error_message'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $chunk));
                }

                $primary->update([
                    'failed_records' => count($errors),
                    'total_records' => $primary->total_records + count($errors),
                    'status' => 'completed_with_errors',
                ]);
            }

            activity('stock_import')
                ->causedBy($user)
                ->performedOn($imports[0])
                ->withProperties([
                    'file' => $file->getClientOriginalName(),
                    'shops' => count($shopIds),
                    'imported' => count($rows),
                    'failed' => count($errors),
                    'replaced' => $replacedTotal,
                    'items_created' => $createdItems,
                ])
                ->log('Stock Report imported and existing stock replaced');

            $duplicates = $this->duplicateGtins($products);

            return [
                'imports' => array_map(fn (StockImport $i) => $i->fresh(['shop', 'importedBy']), $imports),
                'summary' => [
                    'shops' => count($shopIds),
                    'total_rows' => count($rows) + count($errors),
                    'imported' => count($rows),
                    'failed' => count($errors),
                    'replaced' => $replacedTotal,
                    'items_created' => $createdItems,
                    'barcodes_matched' => count($barcodes),
                    // Scan-identifier coverage. A product with no GTIN cannot
                    // be scanned, and a GTIN shared by two products cannot be
                    // resolved to one line — both are reported rather than
                    // resolved by a rule nobody has agreed.
                    'gtin_missing' => count(array_filter($products, fn ($p) => ($p['gtin'] ?? null) === null)),
                    'gtin_duplicates' => count($duplicates),
                ],
            ];
        });
    }

    /**
     * Whole quantity for a stock row.
     *
     * The ERP's own HIGHERQTY is used where the report supplies it. Where it
     * does not, the confirmed relationship `HIGHERQTY = LOWERQTY / FACTOR`
     * fills the gap. Nothing is rounded to a whole number in either path: a
     * fraction of a pack is a real holding, not a rounding error.
     *
     * @param  array<string, mixed>  $row
     */
    private function wholeQuantity(array $row, ?float $factor): ?float
    {
        if ($row['whole_qty'] !== null) {
            return $row['whole_qty'];
        }

        if ($factor === null || $factor == 0.0) {
            return null;
        }

        return round(((float) $row['system_qty']) / $factor, 4);
    }

    /**
     * Creates the products a report introduces, without touching the ones
     * already on file.
     *
     * The business keeps the item master and the stock snapshot as two separate
     * operations: Item Import maintains the product list, Stock Import loads
     * quantities. A stock row still needs a product to point at, so a code that
     * has never been seen is created here — but a product that already exists
     * is left exactly as Item Import left it. Nothing is ever removed, and
     * nothing existing is overwritten.
     *
     * @param  array<string, array<string, mixed>>  $products
     * @param  array<string, string>  $barcodes
     * @return int  how many products this import had to create
     */
    private function syncItemMaster(array $products, array $barcodes, User $user, Carbon $now): int
    {
        if ($products === []) {
            return 0;
        }

        // One barcode per product, taken from any batch that carries one.
        $barcodeByItem = [];

        foreach ($barcodes as $key => $barcode) {
            $itemId = strstr($key, '|', true);

            if ($itemId !== false && ! isset($barcodeByItem[$itemId])) {
                $barcodeByItem[$itemId] = $barcode;
            }
        }

        $payload = [];

        // Only the codes that are genuinely new. Everything already on file
        // belongs to Item Import and is left alone.
        $existing = Item::whereIn('product_code', array_keys($products))
            ->pluck('product_code')
            ->all();

        $missing = array_diff(array_keys($products), $existing);

        foreach ($missing as $code) {
            $product = $products[$code];

            $payload[] = [
                'product_code' => $code,
                'barcode' => $barcodeByItem[$code] ?? null,
                'gtin' => $product['gtin'],
                'description' => $product['description'],
                'uom' => $product['uom'],
                'price' => (float) $product['price'],
                'status' => 'active',
                'created_by' => $user->id,
                'updated_by' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $created = 0;

        // One statement per chunk rather than a query per product.
        foreach (array_chunk($payload, self::INSERT_CHUNK) as $chunk) {
            Item::insert($chunk);
            $created += count($chunk);
        }

        return $created;
    }

    /**
     * Excel stores a date as a day count, and reading without styling means the
     * cell arrives as that plain number.
     */
    private function parseExcelDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $serial = (float) $value;

            // Roughly 1970 to 2150 — outside that it is not a date.
            if ($serial < 25569 || $serial > 91000) {
                return null;
            }

            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject($serial))->toDateString();
            } catch (Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function text(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function error(int $rowNumber, string $column, ?string $value, string $message): array
    {
        return [
            'row_number' => $rowNumber,
            'column_name' => $column,
            'column_value' => $value !== null && $value !== '' ? mb_substr($value, 0, 300) : null,
            'error_message' => $message,
        ];
    }
}
