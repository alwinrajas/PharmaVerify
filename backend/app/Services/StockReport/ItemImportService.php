<?php

namespace App\Services\StockReport;

use App\Exceptions\BusinessRuleException;
use App\Models\Item;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Maintains the item master.
 *
 * The business runs two separate operations against the same export. This one
 * looks after the product list: it is run once to load the master and again
 * whenever products are added or their details change. It never touches stock
 * quantities, and it never removes a product — a code that has stopped
 * appearing in the source system is deactivated by hand, not by an import.
 *
 * Stock Import is the other operation, and it loads quantities. The two are
 * kept apart deliberately: a stock snapshot arriving on a Tuesday should not
 * quietly rewrite the descriptions and prices of the whole catalogue.
 *
 * The file may be the full three-sheet Stock Report, in which case only its
 * `Item Master` sheet is read, or a single sheet carrying the same headings.
 */
class ItemImportService
{
    /** Rows written per statement. */
    private const CHUNK = 1000;

    public function __construct(private readonly StockReportReader $reader) {}

    /**
     * @return array<string, mixed>
     */
    public function import(UploadedFile $file, User $user, bool $updateExisting = true): array
    {
        $path = $file->getRealPath();

        $sheetName = $this->itemSheet($path);
        $headings = $this->reader->headings($path, $sheetName);

        $itemAt = $headings['itemid'] ?? null;
        $nameAt = $headings['itemname'] ?? null;

        if ($itemAt === null || $nameAt === null) {
            throw new BusinessRuleException(
                'The item file must carry an ITEMID and an ITEMNAME column.'
            );
        }

        $unitAt = $headings['invunit'] ?? null;
        $priceAt = $headings['salesprice'] ?? null;
        $gtinAt = $headings['globaltradeitemnumber'] ?? null;

        $positions = array_filter([$itemAt, $nameAt, $unitAt, $priceAt, $gtinAt], fn ($p) => $p !== null);
        $columns = StockReportReader::columnLetters(max($positions) + 1);

        $seen = [];
        $rows = [];
        $skipped = 0;

        $this->reader->eachRowChunked($path, $sheetName, $columns, function (array $cells) use (
            &$seen, &$rows, &$skipped, $itemAt, $nameAt, $unitAt, $priceAt, $gtinAt
        ) {
            $code = trim((string) ($cells[$itemAt] ?? ''));

            if ($code === '') {
                return;
            }

            // A repeated code in one file is the same product listed twice;
            // the first reading wins rather than the last quietly overwriting.
            if (isset($seen[$code])) {
                $skipped++;

                return;
            }

            $seen[$code] = true;

            $price = $priceAt !== null ? ($cells[$priceAt] ?? null) : null;
            $gtin = $gtinAt !== null ? trim((string) ($cells[$gtinAt] ?? '')) : '';
            $name = trim((string) ($cells[$nameAt] ?? ''));
            $unit = $unitAt !== null ? trim((string) ($cells[$unitAt] ?? '')) : '';

            $rows[] = [
                'product_code' => mb_substr($code, 0, 60),
                'description' => mb_substr($name !== '' ? $name : $code, 0, 300),
                'uom' => mb_substr($unit !== '' ? $unit : 'EA', 0, 20),
                // Retail selling price, as confirmed. Cost is a separate figure
                // and is never used in its place.
                'price' => is_numeric($price) ? round((float) $price, 4) : 0.0,
                'gtin' => $gtin !== '' ? mb_substr($gtin, 0, 20) : null,
            ];
        });

        if ($rows === []) {
            throw new BusinessRuleException('No product rows could be read from the file.');
        }

        return $this->persist($rows, $user, $updateExisting, $skipped, $file->getClientOriginalName());
    }

    /**
     * Which sheet holds the products.
     */
    private function itemSheet(string $path): string
    {
        $sheets = $this->reader->sheetNames($path);

        foreach ($sheets as $name) {
            if (strcasecmp(trim($name), StockReportReader::SHEET_ITEMS) === 0) {
                return $name;
            }
        }

        if ($sheets === []) {
            throw new BusinessRuleException('The file contains no readable sheet.');
        }

        // A single-sheet file is taken at face value: whatever is on it is what
        // the user meant to import, named "Item Master" or not.
        if (count($sheets) === 1) {
            return $sheets[0];
        }

        // Several sheets and none of them is Item Master means this is not an
        // item file at all — most often it is the Stock Import workbook (stock
        // + all batches), reached for on the wrong screen. Naming the sheet we
        // needed alongside the sheets actually present is what tells the user
        // which screen they wanted, rather than letting the import fail later
        // with a confusing "missing column" error once it has guessed wrong.
        throw new BusinessRuleException(sprintf(
            'The Item Master sheet was not found. This file has the sheets: %s. Items are imported from the Item Master sheet only; stock and batch data is imported separately from Item Stock Import.',
            implode(', ', $sheets)
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function persist(array $rows, User $user, bool $updateExisting, int $skipped, string $fileName): array
    {
        $now = now();

        return DB::transaction(function () use ($rows, $user, $updateExisting, $skipped, $fileName, $now) {
            $codes = array_column($rows, 'product_code');
            $existing = [];

            foreach (array_chunk($codes, self::CHUNK) as $chunk) {
                foreach (Item::whereIn('product_code', $chunk)->pluck('product_code') as $code) {
                    $existing[$code] = true;
                }
            }

            $created = 0;
            $updated = 0;
            $payload = [];

            foreach ($rows as $row) {
                $isNew = ! isset($existing[$row['product_code']]);

                if (! $isNew && ! $updateExisting) {
                    continue;
                }

                $isNew ? $created++ : $updated++;

                $payload[] = $row + [
                    'status' => 'active',
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($payload, self::CHUNK) as $chunk) {
                Item::upsert(
                    $chunk,
                    ['product_code'],
                    ['description', 'uom', 'price', 'gtin', 'updated_by', 'updated_at']
                );
            }

            $summary = [
                'file_name' => $fileName,
                'rows_read' => count($rows) + $skipped,
                'duplicates_skipped' => $skipped,
                'created' => $created,
                'updated' => $updated,
                'unchanged' => count($rows) - $created - $updated,
            ];

            activity('item_import')
                ->causedBy($user)
                ->withProperties($summary)
                ->log('Item master imported');

            return $summary;
        });
    }
}
