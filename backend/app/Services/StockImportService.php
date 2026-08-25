<?php

namespace App\Services;

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
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Loads a shop's stock file and makes it the shop's current system stock.
 *
 * The import replaces rather than appends: whatever the shop held before the
 * file is removed and the file's contents take its place. The whole exchange
 * runs inside one transaction, so a file that fails part way through leaves the
 * previous stock exactly as it was.
 */
class StockImportService
{
    /**
     * Header aliases accepted for each field. Client files vary in wording, so
     * the importer matches on a normalised form of the heading.
     *
     * @var array<string, array<int, string>>
     */
    private const COLUMN_ALIASES = [
        'shop' => ['shop', 'shopcode', 'shopid', 'branch', 'branchcode', 'store', 'storecode'],
        'barcode' => ['barcode', 'ean', 'eancode', 'barcodeno'],
        'product_code' => ['productcode', 'itemcode', 'code', 'sku', 'materialcode', 'articlecode'],
        'description' => ['productdescription', 'description', 'itemdescription', 'productname', 'itemname', 'product'],
        'system_qty' => ['systemstock', 'systemqty', 'systemquantity', 'stock', 'qty', 'quantity', 'closingstock', 'onhand', 'balance'],
        'uom' => ['uom', 'unit', 'unitofmeasure', 'units'],
        'price' => ['price', 'mrp', 'rate', 'unitprice', 'sellingprice'],
        'batch' => ['batch', 'batchno', 'batchnumber', 'lot', 'lotno'],
        'expiry_date' => ['expiry', 'expirydate', 'expdate', 'exp', 'expiredate'],
        'shelf_location' => ['shelf', 'shelflocation', 'location', 'rack', 'bin', 'binlocation'],
    ];

    /** Columns the file cannot be processed without. */
    private const REQUIRED_COLUMNS = ['product_code', 'description', 'system_qty'];

    /**
     * @return array{import: StockImport, errors: array<int, array<string, mixed>>}
     */
    public function import(UploadedFile $file, Shop $shop, User $user): array
    {
        $this->assertFileType($file);

        $storedPath = $file->store('imports');

        $rows = $this->readRows($file->getRealPath() ?: storage_path('app/'.$storedPath));

        if (count($rows) < 2) {
            throw new BusinessRuleException('The selected file does not contain any stock rows.');
        }

        $headerRow = array_shift($rows);
        $map = $this->mapColumns($headerRow);

        $missing = array_diff(self::REQUIRED_COLUMNS, array_keys($map));

        if ($missing !== []) {
            throw new BusinessRuleException(sprintf(
                'The file is missing the required column(s): %s. Expected headings such as Product Code, Product Description and System Stock.',
                implode(', ', array_map(fn ($key) => $this->readableColumn($key), $missing))
            ));
        }

        [$validRows, $errors] = $this->validateRows($rows, $map, $shop);

        if ($validRows === []) {
            throw new BusinessRuleException(sprintf(
                'Stock import could not be completed. All %d row(s) contain validation errors and the existing stock has been left unchanged.',
                count($errors)
            ));
        }

        // Everything below either succeeds together or is rolled back together.
        return DB::transaction(function () use ($shop, $user, $file, $storedPath, $rows, $validRows, $errors) {
            $import = StockImport::create([
                'shop_id' => $shop->id,
                'file_name' => $file->getClientOriginalName(),
                'stored_path' => $storedPath,
                'total_records' => count($rows),
                'success_records' => 0,
                'failed_records' => count($errors),
                'status' => 'processing',
                'imported_by' => $user->id,
                'imported_at' => now(),
            ]);

            $replaced = ItemStock::where('shop_id', $shop->id)->count();
            ItemStock::where('shop_id', $shop->id)->delete();

            $itemLookup = Item::pluck('id', 'product_code');
            $inserted = 0;
            $now = now();
            $buffer = [];

            foreach ($validRows as $row) {
                $buffer[] = [
                    'shop_id' => $shop->id,
                    'item_id' => $itemLookup[$row['product_code']] ?? null,
                    'stock_import_id' => $import->id,
                    'product_code' => $row['product_code'],
                    'barcode' => $row['barcode'],
                    'description' => $row['description'],
                    'system_qty' => $row['system_qty'],
                    'uom' => $row['uom'],
                    'price' => $row['price'],
                    'batch' => $row['batch'],
                    'expiry_date' => $row['expiry_date'],
                    'shelf_location' => $row['shelf_location'],
                    'verification_status' => 'not_verified',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($buffer) === 500) {
                    ItemStock::insert($buffer);
                    $inserted += count($buffer);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                ItemStock::insert($buffer);
                $inserted += count($buffer);
            }

            foreach (array_chunk($errors, 500) as $chunk) {
                StockImportError::insert(array_map(fn ($error) => [
                    'stock_import_id' => $import->id,
                    'row_number' => $error['row_number'],
                    'column_name' => $error['column_name'],
                    'column_value' => $error['column_value'],
                    'error_message' => $error['error_message'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            $import->update([
                'success_records' => $inserted,
                'replaced_records' => $replaced,
                'status' => $errors === [] ? 'completed' : 'completed_with_errors',
            ]);

            activity('stock_import')
                ->causedBy($user)
                ->performedOn($import)
                ->withProperties([
                    'shop' => $shop->shop_code,
                    'file' => $import->file_name,
                    'replaced' => $replaced,
                    'imported' => $inserted,
                    'failed' => count($errors),
                ])
                ->log('Stock imported and existing stock replaced');

            return [
                'import' => $import->fresh(['shop', 'importedBy']),
                'errors' => $errors,
            ];
        });
    }

    private function assertFileType(UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, ['xls', 'xlsx'], true)) {
            throw new BusinessRuleException('Only Excel files with an .xls or .xlsx extension can be imported.');
        }
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function readRows(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(false);
            $spreadsheet = $reader->load($path);
        } catch (Throwable) {
            throw new BusinessRuleException('The selected file could not be opened. Please confirm it is a valid Excel workbook.');
        }

        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        $rows = [];

        for ($rowIndex = 1; $rowIndex <= $highestRow; $rowIndex++) {
            $row = [];
            $hasValue = false;

            for ($col = 1; $col <= $highestColumn; $col++) {
                $cell = $sheet->getCell([$col, $rowIndex]);
                $value = $cell->getValue();

                if ($value !== null && $value !== '') {
                    $hasValue = true;
                }

                $row[] = [
                    'value' => $value,
                    'is_date' => ExcelDate::isDateTime($cell),
                ];
            }

            if ($hasValue) {
                $rows[] = ['index' => $rowIndex, 'cells' => $row];
            }
        }

        $spreadsheet->disconnectWorksheets();

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $headerRow
     * @return array<string, int>
     */
    private function mapColumns(array $headerRow): array
    {
        $map = [];

        foreach ($headerRow['cells'] as $position => $cell) {
            $heading = $this->normalise((string) ($cell['value'] ?? ''));

            if ($heading === '') {
                continue;
            }

            foreach (self::COLUMN_ALIASES as $field => $aliases) {
                if (isset($map[$field])) {
                    continue;
                }

                if (in_array($heading, $aliases, true)) {
                    $map[$field] = $position;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $map
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function validateRows(array $rows, array $map, Shop $shop): array
    {
        $valid = [];
        $errors = [];
        $seen = [];

        foreach ($rows as $row) {
            $rowNumber = $row['index'];
            $cells = $row['cells'];

            $get = function (string $field) use ($map, $cells) {
                if (! isset($map[$field])) {
                    return null;
                }

                return $cells[$map[$field]]['value'] ?? null;
            };

            $productCode = trim((string) $get('product_code'));
            $description = trim((string) $get('description'));
            $rawQty = $get('system_qty');

            if ($productCode === '') {
                $errors[] = $this->error($rowNumber, 'Product Code', null, 'Product code is required.');

                continue;
            }

            if ($description === '') {
                $errors[] = $this->error($rowNumber, 'Product Description', null, 'Product description is required.');

                continue;
            }

            if ($rawQty === null || $rawQty === '' || ! is_numeric($rawQty)) {
                $errors[] = $this->error($rowNumber, 'System Stock', (string) $rawQty, 'System stock must be a number.');

                continue;
            }

            if ((float) $rawQty < 0) {
                $errors[] = $this->error($rowNumber, 'System Stock', (string) $rawQty, 'System stock cannot be negative.');

                continue;
            }

            // When the file carries a shop column it must agree with the shop
            // the user selected, otherwise stock would land against the wrong branch.
            $shopValue = trim((string) $get('shop'));

            if ($shopValue !== '' && ! $this->shopMatches($shopValue, $shop)) {
                $errors[] = $this->error($rowNumber, 'Shop', $shopValue, sprintf(
                    'This row belongs to a different shop. The import is running for %s.',
                    $shop->shop_code
                ));

                continue;
            }

            $batch = trim((string) $get('batch'));
            $expiry = $this->parseDate($get('expiry_date'), $map, $cells);

            if (isset($map['expiry_date'])) {
                $rawExpiry = $get('expiry_date');

                if ($rawExpiry !== null && $rawExpiry !== '' && $expiry === null) {
                    $errors[] = $this->error($rowNumber, 'Expiry Date', (string) $rawExpiry, 'Expiry date could not be read. Use a date such as 2027-06-30.');

                    continue;
                }
            }

            $key = $productCode.'|'.$batch;

            if (isset($seen[$key])) {
                $errors[] = $this->error($rowNumber, 'Product Code', $productCode, sprintf(
                    'Duplicate of row %d. The same product and batch cannot appear twice in one file.',
                    $seen[$key]
                ));

                continue;
            }

            $seen[$key] = $rowNumber;

            $price = $get('price');
            $uom = trim((string) $get('uom'));

            $valid[] = [
                'product_code' => $productCode,
                'barcode' => trim((string) $get('barcode')) ?: null,
                'description' => mb_substr($description, 0, 300),
                'system_qty' => round((float) $rawQty, 3),
                'uom' => $uom !== '' ? mb_substr($uom, 0, 20) : 'EA',
                'price' => is_numeric($price) ? round((float) $price, 4) : 0,
                'batch' => mb_substr($batch, 0, 60),
                'expiry_date' => $expiry,
                'shelf_location' => trim((string) $get('shelf_location')) ?: null,
            ];
        }

        return [$valid, $errors];
    }

    private function shopMatches(string $value, Shop $shop): bool
    {
        $normalised = $this->normalise($value);

        return in_array($normalised, [
            $this->normalise($shop->shop_code),
            $this->normalise($shop->shop_name),
            (string) $shop->id,
        ], true);
    }

    /**
     * @param  array<string, int>  $map
     * @param  array<int, array<string, mixed>>  $cells
     */
    private function parseDate(mixed $value, array $map, array $cells): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Excel stores real dates as serial numbers.
        if (is_numeric($value) && isset($map['expiry_date']) && ($cells[$map['expiry_date']]['is_date'] ?? false)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
            } catch (Throwable) {
                return null;
            }
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'd-M-Y', 'd M Y', 'Y/m/d'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format.'|', trim((string) $value));
            } catch (Throwable) {
                continue;
            }

            if ($parsed instanceof Carbon) {
                return $parsed->toDateString();
            }
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function error(int $rowNumber, string $column, ?string $value, string $message): array
    {
        return [
            'row_number' => $rowNumber,
            'column_name' => $column,
            'column_value' => $value !== null ? mb_substr($value, 0, 300) : null,
            'error_message' => $message,
        ];
    }

    private function normalise(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $value) ?? '');
    }

    private function readableColumn(string $key): string
    {
        return match ($key) {
            'product_code' => 'Product Code',
            'description' => 'Product Description',
            'system_qty' => 'System Stock',
            default => ucwords(str_replace('_', ' ', $key)),
        };
    }
}
