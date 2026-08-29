<?php

namespace App\Services\HhtImport;

use App\Exceptions\BusinessRuleException;
use App\Models\StockTake;
use App\Models\StockTakeSession;
use App\Models\User;
use App\Support\SessionReference;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reads a handheld's Stock Take export into a cycle and its counted lines.
 *
 * A take is a **blind** physical count. The device omits SYSTEMQTY and VARIANCE
 * from the export on purpose — its own source says an empty column "invites the
 * receiving side to fill it in from somewhere else" — and that is honoured
 * here: no system quantity is looked up, and no variance is computed. What was
 * on the shelf is recorded as what was on the shelf.
 *
 * Products are still resolved where they can be, so a counted line can point at
 * the stock it matches, but a line that matches nothing is kept rather than
 * dropped. An unrecognised product is precisely what a stock take exists to
 * surface.
 */
class StockTakeExcelImportService extends HhtImportBase
{
    /**
     * @return array<string, mixed>
     */
    public function preview(UploadedFile $file): array
    {
        $prepared = $this->prepare($file);

        $existing = $this->existingSession($prepared);
        $duplicate = $existing && $existing->payload_hash === $prepared['fingerprint'];

        return [
            'kind' => HhtExcelReader::KIND_STOCK_TAKE,
            'file_name' => $file->getClientOriginalName(),
            'reference' => $prepared['reference'],
            'take_number' => $prepared['take_number'],
            'take_date' => $prepared['take_date'],
            'shop' => [
                'shop_id' => $prepared['shop']->id,
                'shop_code' => $prepared['shop']->shop_code,
                'shop_name' => $prepared['shop']->shop_name,
                'ax_location_id' => $prepared['shop']->ax_location_id,
            ],
            'device' => null,
            'total_rows' => count($prepared['rows']) + count($prepared['errors']),
            'valid_rows' => count($prepared['rows']),
            'invalid_rows' => count($prepared['errors']),
            'unmatched_locations' => $prepared['unmatched'],
            'items_matched' => count(array_filter($prepared['rows'], fn ($r) => $r['item_stock_id'] !== null)),
            'items_unmatched' => count(array_filter($prepared['rows'], fn ($r) => $r['item_stock_id'] === null)),
            'matched_on' => $prepared['matched_on'],
            'lines_with_loose' => count(array_filter($prepared['rows'], fn ($r) => (float) $r['loose_qty'] > 0)),
            // A blind count is measured against nothing, so neither of these
            // can arise here.
            'system_qty_disagreements' => 0,
            'variance_disagreements' => 0,
            'sample_errors' => array_slice($prepared['errors'], 0, 20),
            'action' => $duplicate ? 'duplicate_ignored' : ($existing ? 'conflict' : 'create'),
            'conflict' => $existing && ! $duplicate ? [
                'stock_take_session_id' => $existing->id,
                'take_ref' => $existing->take_ref,
                'source' => $existing->source,
                'created_at' => $existing->created_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * @return array{session: StockTakeSession, duplicate: bool, summary: array<string, mixed>}
     */
    public function import(UploadedFile $file, User $user): array
    {
        $prepared = $this->prepare($file);
        $existing = $this->existingSession($prepared);

        if ($existing && $existing->payload_hash === $prepared['fingerprint']) {
            return [
                'session' => $existing,
                'duplicate' => true,
                'summary' => ['reference' => $prepared['reference'], 'imported' => 0, 'action' => 'duplicate_ignored'],
            ];
        }

        if ($existing) {
            throw new BusinessRuleException(sprintf(
                '%s has already been recorded for %s, and this file does not match it. Replacing an imported stock '
                .'take is a separate decision, so nothing has been changed.',
                $prepared['reference'],
                $prepared['shop']->shop_code
            ), 409);
        }

        if ($prepared['rows'] === []) {
            throw new BusinessRuleException(sprintf(
                'None of the %d row(s) in this file could be used, so nothing has been imported.',
                count($prepared['errors'])
            ));
        }

        return DB::transaction(function () use ($prepared, $user, $file) {
            $shop = $prepared['shop'];

            $session = StockTakeSession::create([
                'shop_id' => $shop->id,
                'take_ref' => $prepared['reference'],
                'take_number' => $prepared['take_number'],
                'take_date' => $prepared['take_date'],
                'status' => StockTakeSession::STATUS_COMPLETED,
                'item_count' => count($prepared['rows']),
                'source' => StockTakeSession::SOURCE_EXCEL,
                'payload_hash' => $prepared['fingerprint'],
                'file_name' => $file->getClientOriginalName(),
                'counted_by_name' => $prepared['counted_by'],
                'created_by' => $user->id,
                'completed_at' => now(),
            ]);

            $now = now();
            $buffer = [];

            foreach ($prepared['rows'] as $row) {
                $buffer[] = [
                    'shop_id' => $shop->id,
                    'stock_take_session_id' => $session->id,
                    'barcode' => $row['barcode'],
                    'product_code' => $row['product_code'],
                    'description' => $row['description'],
                    'physical_qty' => $row['physical_qty'],
                    'loose_qty' => $row['loose_qty'],
                    'uom' => $row['uom'],
                    'batch' => $row['batch'],
                    'expiry_date' => $row['expiry_date'],
                    'status' => 'recorded',
                    'taken_by' => $user->id,
                    'taken_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($buffer) === self::INSERT_CHUNK) {
                    StockTake::insert($buffer);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                StockTake::insert($buffer);
            }

            activity('hht_import')
                ->causedBy($user)
                ->performedOn($session)
                ->withProperties([
                    'file' => $file->getClientOriginalName(),
                    'reference' => $prepared['reference'],
                    'shop' => $shop->shop_code,
                    'imported' => count($prepared['rows']),
                    'rejected' => count($prepared['errors']),
                ])
                ->log('Stock take imported from a handheld export');

            return [
                'session' => $session->fresh(['shop', 'createdBy']),
                'duplicate' => false,
                'summary' => [
                    'reference' => $prepared['reference'],
                    'imported' => count($prepared['rows']),
                    'rejected' => count($prepared['errors']),
                    'unmatched_locations' => $prepared['unmatched'],
                    'items_unmatched' => count(array_filter($prepared['rows'], fn ($r) => $r['item_stock_id'] === null)),
                    'lines_with_loose' => count(array_filter($prepared['rows'], fn ($r) => (float) $r['loose_qty'] > 0)),
                    'action' => 'created',
                ],
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function prepare(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $detected = $this->reader->detect($path);

        if ($detected['kind'] !== HhtExcelReader::KIND_STOCK_TAKE) {
            throw new BusinessRuleException(
                'This is an audit export, not a stock take. Import it from the Stock Audit side so its counts are '
                .'measured against the system quantity.'
            );
        }

        $identity = $this->reader->identify($path, $detected['sheet'], $detected['headings'], $detected['kind']);
        $shops = $this->shopsByLocation();
        $resolved = $this->resolveShop($identity['locations'], $shops, $identity['reference']);
        $shop = $resolved['shop'];

        $rows = [];
        $errors = [];
        $seen = [];
        $matchedOn = ['gtin' => 0, 'product_code' => 0, 'barcode' => 0, 'none' => 0];
        $countedBy = null;
        $countedDate = null;

        $this->reader->eachRow($path, $detected['sheet'], $detected['headings'], function (array $row, int $rowNumber) use (
            &$rows, &$errors, &$seen, &$matchedOn, &$countedBy, &$countedDate, $shop, $shops
        ) {
            $location = strtoupper($this->text($row['inventlocationid'] ?? null));
            $itemId = $this->text($row['itemid'] ?? null);
            $rawPhysical = $row['physicalqty'] ?? null;

            if ($location === '' && $itemId === '' && ($rawPhysical === null || $rawPhysical === '')) {
                return;
            }

            if (! isset($shops[$location]) || $shops[$location]->id !== $shop->id) {
                $errors[] = $this->error($rowNumber, 'INVENTLOCATIONID', $location, sprintf(
                    'No shop is linked to warehouse code %s, so this row cannot be counted.',
                    $location ?: '(blank)'
                ));

                return;
            }

            $description = $this->text($row['itemname'] ?? null);

            if ($itemId === '' && $this->text($row['itembarcode'] ?? null) === '' && $description === '') {
                $errors[] = $this->error($rowNumber, 'ITEMID', null, 'A row needs an item code, a scanned code or a description.');

                return;
            }

            if ($rawPhysical === null || $rawPhysical === '' || ! is_numeric($rawPhysical)) {
                $errors[] = $this->error($rowNumber, 'PHYSICALQTY', $this->text($rawPhysical), 'The physical quantity must be a number.');

                return;
            }

            if ((float) $rawPhysical < 0) {
                $errors[] = $this->error($rowNumber, 'PHYSICALQTY', $this->text($rawPhysical), 'A physical quantity cannot be negative.');

                return;
            }

            $rawLoose = $row['lzqty'] ?? null;

            if ($rawLoose !== null && $rawLoose !== '' && ! is_numeric($rawLoose)) {
                $errors[] = $this->error($rowNumber, 'LZQTY', $this->text($rawLoose), 'The loose quantity must be a number.');

                return;
            }

            if (is_numeric($rawLoose) && (float) $rawLoose < 0) {
                $errors[] = $this->error($rowNumber, 'LZQTY', $this->text($rawLoose), 'A loose quantity cannot be negative.');

                return;
            }

            $batch = $this->text($row['inventbatchid'] ?? null);
            $rawExpiry = $row['expdate'] ?? null;
            $expiry = $this->parseDate($rawExpiry);

            if ($rawExpiry !== null && $rawExpiry !== '' && $expiry === null) {
                $errors[] = $this->error($rowNumber, 'EXPDATE', $this->text($rawExpiry), 'The expiry date could not be read.');

                return;
            }

            $key = $itemId.'|'.$batch.'|'.$description;

            if (isset($seen[$key])) {
                $errors[] = $this->error($rowNumber, 'ITEMID', $itemId, sprintf(
                    'Duplicate of row %d — the same item and batch is counted twice in this file.',
                    $seen[$key]
                ));

                return;
            }

            $seen[$key] = $rowNumber;

            $stock = $this->resolveStock($shop->id, $row);
            $scanned = $this->text($row['itembarcode'] ?? null);

            if ($stock === null) {
                $matchedOn['none']++;
            } elseif ($scanned !== '' && $stock->gtin === $scanned) {
                $matchedOn['gtin']++;
            } elseif ($stock->product_code === $itemId) {
                $matchedOn['product_code']++;
            } else {
                $matchedOn['barcode']++;
            }

            if ($countedBy === null) {
                $countedBy = $this->text($row['countedby'] ?? null) ?: null;
            }

            if ($countedDate === null) {
                $countedDate = $this->parseDate($row['counteddate'] ?? null);
            }

            $rows[] = [
                'item_stock_id' => $stock?->id,
                'product_code' => $itemId !== '' ? $itemId : $stock?->product_code,
                'barcode' => $scanned !== '' ? $scanned : $stock?->barcode,
                'description' => $description ?: ($stock?->description ?? 'Unknown product'),
                'physical_qty' => round((float) $rawPhysical, 3),
                'loose_qty' => is_numeric($rawLoose) ? round((float) $rawLoose, 3) : 0.0,
                'uom' => $stock?->uom ?? 'EA',
                'batch' => $batch,
                'expiry_date' => $expiry ?? $stock?->expiry_date?->toDateString(),
            ];
        });

        $parsed = SessionReference::parse($identity['reference']);

        return [
            'reference' => $identity['reference'],
            'take_number' => $parsed['sequence'],
            'take_date' => $countedDate ?? Carbon::createFromFormat('dmY', $parsed['date'])->toDateString(),
            'counted_by' => $countedBy,
            'shop' => $shop,
            'unmatched' => $resolved['unmatched'],
            'rows' => $rows,
            'errors' => $errors,
            'matched_on' => $matchedOn,
            'fingerprint' => $this->fingerprint($shop->shop_code, $identity['reference'], $rows),
        ];
    }

    /**
     * @param  array<string, mixed>  $prepared
     */
    private function existingSession(array $prepared): ?StockTakeSession
    {
        return StockTakeSession::withTrashed()
            ->where('shop_id', $prepared['shop']->id)
            ->where('take_ref', $prepared['reference'])
            ->first();
    }
}
