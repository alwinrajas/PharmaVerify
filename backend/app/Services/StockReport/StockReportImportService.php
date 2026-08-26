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
                'No shop has been linked to a warehouse code yet. Set the AX location on each shop before importing a Stock Report.'
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
     * Shops keyed by their warehouse code.
     *
     * @return array<string, Shop>
     */
    private function shopsByLocation(): array
    {
        $shops = [];

        foreach (Shop::whereNotNull('ax_location_id')->get() as $shop) {
            $shops[strtoupper(trim((string) $shop->ax_location_id))] = $shop;
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
        $qtyAt = $at('lowerqty');
        $costAt = $at('costperinvunit');

        $columns = StockReportReader::columnLetters(max(array_values($headings)) + 1);

        $rows = [];
        $errors = [];
        $seen = [];
        $needed = ['items' => [], 'batches' => []];

        $this->reader->eachRowChunked($path, $sheetName, $columns, function (array $cells, int $rowNumber) use (
            &$rows, &$errors, &$seen, &$needed, $shops, $onlyShop,
            $locationAt, $itemAt, $batchAt, $expiryAt, $qtyAt, $costAt
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

            $rows[] = [
                'shop_id' => $shop->id,
                'product_code' => $itemId,
                'batch' => $batch,
                'system_qty' => round((float) $rawQty, 3),
                'expiry_date' => $expiry,
                'cost' => $cost,
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
     * @param  array<string, true>  $wanted
     * @return array<string, array{description: string, uom: string, price: float, barcode: ?string}>
     */
    private function readItemMaster(string $path, string $sheetName, array $wanted): array
    {
        $headings = $this->reader->headings($path, $sheetName);
        $itemAt = $headings['itemid'] ?? 0;
        $nameAt = $headings['itemname'] ?? 1;
        $unitAt = $headings['invunit'] ?? null;
        $priceAt = $headings['salesprice'] ?? $headings['costprice'] ?? null;
        $gtinAt = $headings['globaltradeitemnumber'] ?? null;

        $positions = array_filter([$itemAt, $nameAt, $unitAt, $priceAt, $gtinAt], fn ($p) => $p !== null);
        $columns = StockReportReader::columnLetters(max($positions) + 1);

        $found = [];

        $this->reader->eachRowChunked($path, $sheetName, $columns, function (array $cells) use (
            &$found, $wanted, $itemAt, $nameAt, $unitAt, $priceAt, $gtinAt
        ) {
            $id = $this->text($cells[$itemAt] ?? null);

            if ($id === '' || ! isset($wanted[$id]) || isset($found[$id])) {
                return;
            }

            $price = $priceAt !== null ? ($cells[$priceAt] ?? null) : null;
            $gtin = $gtinAt !== null ? $this->text($cells[$gtinAt] ?? null) : '';

            $found[$id] = [
                'description' => mb_substr($this->text($cells[$nameAt] ?? null) ?: $id, 0, 300),
                'uom' => mb_substr($unitAt !== null ? ($this->text($cells[$unitAt] ?? null) ?: 'EA') : 'EA', 0, 20),
                'price' => is_numeric($price) ? round((float) $price, 4) : 0.0,
                'barcode' => $gtin !== '' ? mb_substr($gtin, 0, 60) : null,
            ];
        });

        return $found;
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
            $syncedItems = $this->syncItemMaster($products, $barcodes, $user, $now);
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
                    $barcode = $barcodes[$row['product_code'].'|'.$row['batch']]
                        ?? $product['barcode']
                        ?? null;

                    $buffer[] = [
                        'shop_id' => $shopId,
                        'item_id' => $itemIds[$row['product_code']] ?? null,
                        'stock_import_id' => $import->id,
                        'product_code' => $row['product_code'],
                        'barcode' => $barcode,
                        'description' => $product['description'] ?? $row['product_code'],
                        'system_qty' => $row['system_qty'],
                        'uom' => $product['uom'] ?? 'EA',
                        'price' => (float) ($product['price'] ?? $row['cost'] ?? 0),
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
                    'items_synced' => $syncedItems,
                ])
                ->log('Stock Report imported and existing stock replaced');

            return [
                'imports' => array_map(fn (StockImport $i) => $i->fresh(['shop', 'importedBy']), $imports),
                'summary' => [
                    'shops' => count($shopIds),
                    'total_rows' => count($rows) + count($errors),
                    'imported' => count($rows),
                    'failed' => count($errors),
                    'replaced' => $replacedTotal,
                    'items_synced' => $syncedItems,
                    'barcodes_matched' => count($barcodes),
                ],
            ];
        });
    }

    /**
     * Brings the item master in line with the report.
     *
     * Confirmed with the business: a Stock Report may create products it
     * introduces and refresh the details of ones already known, so the product
     * list tracks the source system. Nothing is ever removed.
     *
     * @param  array<string, array<string, mixed>>  $products
     * @param  array<string, string>  $barcodes
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

        foreach ($products as $code => $product) {
            $payload[] = [
                'product_code' => $code,
                'barcode' => $barcodes === [] ? $product['barcode'] : ($barcodeByItem[$code] ?? $product['barcode']),
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

        $synced = 0;

        // One statement per chunk rather than a query per product.
        foreach (array_chunk($payload, self::INSERT_CHUNK) as $chunk) {
            Item::upsert(
                $chunk,
                ['product_code'],
                ['barcode', 'description', 'uom', 'price', 'updated_by', 'updated_at']
            );
            $synced += count($chunk);
        }

        return $synced;
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
