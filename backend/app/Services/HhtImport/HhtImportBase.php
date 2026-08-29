<?php

namespace App\Services\HhtImport;

use App\Exceptions\BusinessRuleException;
use App\Models\ItemStock;
use App\Models\Shop;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * What the two handheld importers share.
 *
 * Both read the same row shape, resolve a shop the same way, identify a
 * product the same way and reject a bad value the same way. The differences —
 * what a row becomes, and what it is written into — live in the subclasses.
 */
abstract class HhtImportBase
{
    /** Rows written per insert. */
    protected const INSERT_CHUNK = 500;

    public function __construct(protected readonly HhtExcelReader $reader) {}

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
    protected function shopsByLocation(): array
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

        if ($shops === []) {
            throw new BusinessRuleException(
                'There are no shops to import against yet. Create the shops first — a shop coded P001 will match the '
                .'export rows for warehouse P001 automatically.'
            );
        }

        return $shops;
    }

    /**
     * The single shop this file belongs to.
     *
     * A reference is issued per shop, so a workbook carrying one reference and
     * two known branches is contradictory and is refused rather than split.
     * Codes no shop answers to are reported instead — their rows are dropped,
     * never reassigned.
     *
     * @param  array<int, string>  $locations
     * @param  array<string, Shop>  $shops
     * @return array{shop: Shop, unmatched: array<int, string>}
     */
    protected function resolveShop(array $locations, array $shops, string $reference): array
    {
        $matched = [];
        $unmatched = [];

        foreach ($locations as $code) {
            if (isset($shops[$code])) {
                $matched[$code] = $shops[$code];
            } else {
                $unmatched[] = $code;
            }
        }

        if ($matched === []) {
            throw new BusinessRuleException(sprintf(
                'None of the warehouse code(s) in this file (%s) is linked to a shop, so %s cannot be imported. '
                .'Set the AX location on the shop, then import again.',
                implode(', ', $unmatched) ?: '—',
                $reference
            ));
        }

        if (count($matched) > 1) {
            throw new BusinessRuleException(sprintf(
                '%s carries rows for %d different shops (%s). A reference belongs to one shop, so this file cannot '
                .'be imported as it stands.',
                $reference,
                count($matched),
                implode(', ', array_keys($matched))
            ));
        }

        return ['shop' => reset($matched), 'unmatched' => $unmatched];
    }

    /**
     * Finds the stock line a counted row refers to.
     *
     * GTIN first — it is the identifier the handheld scans — then the ERP item
     * code, then the older 7-digit internal barcode. The barcode is a
     * controlled fallback and is only reached once the other two have found
     * nothing, so it can never displace the GTIN as the primary match.
     *
     * Where a batch is given it narrows the match; where it is not, the
     * earliest expiry wins, which is the stock a shelf is worked from.
     *
     * @param  array<string, mixed>  $row
     */
    protected function resolveStock(int $shopId, array $row): ?ItemStock
    {
        $scanned = $this->text($row['itembarcode'] ?? null);
        $itemId = $this->text($row['itemid'] ?? null);
        $batch = $this->text($row['inventbatchid'] ?? null);

        $candidates = [];

        if ($scanned !== '') {
            $candidates[] = ['gtin', $scanned];
        }

        if ($itemId !== '') {
            $candidates[] = ['product_code', $itemId];
        }

        if ($scanned !== '') {
            $candidates[] = ['barcode', $scanned];
        }

        foreach ($candidates as [$column, $value]) {
            $query = ItemStock::where('shop_id', $shopId)->where($column, $value);

            if ($batch !== '') {
                $match = (clone $query)->where('batch', $batch)->first();

                if ($match) {
                    return $match;
                }
            }

            $match = $query->orderBy('expiry_date')->first();

            if ($match) {
                return $match;
            }
        }

        return null;
    }

    /**
     * A fingerprint of what the file actually says.
     *
     * Sorted and canonicalised, so re-exporting the same count in a different
     * row order still recognises as the same file. Only the figures that carry
     * meaning are included — a changed operator name or timestamp does not make
     * it a different count.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function fingerprint(string $shopCode, string $reference, array $rows): string
    {
        $canonical = array_map(fn (array $row) => implode('|', [
            $row['product_code'] ?? '',
            $row['batch'] ?? '',
            $row['expiry_date'] ?? '',
            number_format((float) ($row['physical_qty'] ?? 0), 3, '.', ''),
            number_format((float) ($row['loose_qty'] ?? 0), 3, '.', ''),
        ]), $rows);

        sort($canonical);

        return hash('sha256', $shopCode.'|'.$reference.'|'.implode("\n", $canonical));
    }

    // ------------------------------------------------------------- parsing

    /**
     * Excel stores a date as a day count, and reading without styling means the
     * cell arrives as that plain number.
     */
    protected function parseDate(mixed $value): ?string
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

    protected function text(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    protected function error(int $rowNumber, string $column, ?string $value, string $message): array
    {
        return [
            'row_number' => $rowNumber,
            'column_name' => $column,
            'column_value' => $value !== null && $value !== '' ? mb_substr($value, 0, 300) : null,
            'error_message' => $message,
        ];
    }
}
