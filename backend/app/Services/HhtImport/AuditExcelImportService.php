<?php

namespace App\Services\HhtImport;

use App\Exceptions\BusinessRuleException;
use App\Models\Audit;
use App\Models\AuditLine;
use App\Models\Device;
use App\Models\HhtSubmission;
use App\Models\Shop;
use App\Models\User;
use App\Support\SessionReference;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reads a handheld's Audit export into an audit and its counted lines.
 *
 * The device has no network path, so a completed count reaches PharmaVerify as
 * a workbook. This is the second inbound route to the same tables the JSON
 * endpoint writes; both converge on the same variance rule, and `audits.source`
 * records which one produced any given audit.
 *
 * Two things the file says are deliberately not believed:
 *
 *  - **VARIANCE.** Recomputed here from the quantities, because the device uses
 *    the opposite sign convention. The file's figure is compared against the
 *    recomputed one and a disagreement is reported.
 *  - **SYSTEMQTY.** Kept as `source_system_qty` for reconciliation, while the
 *    audit line takes its system quantity from PharmaVerify's own stock. The
 *    device works from its own copy of the ERP data, taken at its own moment.
 */
class AuditExcelImportService extends HhtImportBase
{
    /**
     * Judges a file without writing anything.
     *
     * @return array<string, mixed>
     */
    public function preview(UploadedFile $file, ?Device $device = null): array
    {
        $prepared = $this->prepare($file, $device);

        $existingAudit = $this->existingAudit($prepared)
            ?? ($device ? $this->existingByIdentity($prepared, $device) : null);
        $duplicate = $this->existingSubmission($prepared);

        return [
            'kind' => HhtExcelReader::KIND_AUDIT,
            'file_name' => $file->getClientOriginalName(),
            'reference' => $prepared['reference'],
            'audit_number' => $prepared['audit_number'],
            'audit_date' => $prepared['audit_date'],
            'shop' => [
                'shop_id' => $prepared['shop']->id,
                'shop_code' => $prepared['shop']->shop_code,
                'shop_name' => $prepared['shop']->shop_name,
                'ax_location_id' => $prepared['shop']->ax_location_id,
            ],
            'device' => $device ? [
                'device_id' => $device->id,
                'device_code' => $device->device_code,
            ] : null,
            'total_rows' => count($prepared['rows']) + count($prepared['errors']),
            'valid_rows' => count($prepared['rows']),
            'invalid_rows' => count($prepared['errors']),
            'unmatched_locations' => $prepared['unmatched'],
            'items_matched' => count(array_filter($prepared['rows'], fn ($r) => $r['item_stock_id'] !== null)),
            'items_unmatched' => count(array_filter($prepared['rows'], fn ($r) => $r['item_stock_id'] === null)),
            'matched_on' => $prepared['matched_on'],
            'lines_with_loose' => count(array_filter($prepared['rows'], fn ($r) => (float) $r['loose_qty'] > 0)),
            'system_qty_disagreements' => $prepared['drift'],
            'variance_disagreements' => $prepared['variance_mismatch'],
            'sample_errors' => array_slice($prepared['errors'], 0, 20),
            // What committing would do, said plainly.
            'action' => $duplicate ? 'duplicate_ignored' : ($existingAudit ? 'conflict' : 'create'),
            'conflict' => $existingAudit && ! $duplicate ? [
                'audit_id' => $existingAudit->id,
                'audit_ref' => $existingAudit->reference(),
                'source' => $existingAudit->source,
                'submitted_at' => $existingAudit->submitted_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * @return array{audit: Audit, duplicate: bool, summary: array<string, mixed>}
     */
    public function import(UploadedFile $file, User $user, Device $device): array
    {
        $prepared = $this->prepare($file, $device);

        // A retry, or the same file uploaded twice. Acknowledged, not repeated.
        $duplicate = $this->existingSubmission($prepared);

        if ($duplicate && $duplicate->audit) {
            return [
                'audit' => $duplicate->audit,
                'duplicate' => true,
                'summary' => ['reference' => $prepared['reference'], 'imported' => 0, 'action' => 'duplicate_ignored'],
            ];
        }

        $existing = $this->existingAudit($prepared);

        if ($existing) {
            throw new BusinessRuleException(sprintf(
                '%s has already been recorded for %s on device %s, and this file does not match it. Replacing an '
                .'imported count is a separate decision, so nothing has been changed.',
                $prepared['reference'],
                $prepared['shop']->shop_code,
                $device->device_code
            ), 409);
        }

        // Identity here is shop + device + audit number, and the reference's
        // numeric tail becomes that number. A count already recorded under it —
        // most likely submitted from a handheld over the API — would otherwise
        // surface as a database constraint error rather than a sentence.
        $clash = $this->existingByIdentity($prepared, $device);

        if ($clash) {
            throw new BusinessRuleException(sprintf(
                'Audit number %d has already been recorded for %s on device %s (%s, received %s). An audit is '
                .'identified by its shop, its device and its number, so this file cannot be imported against the '
                .'same device. Choose the handheld it was actually counted on.',
                $prepared['audit_number'],
                $prepared['shop']->shop_code,
                $device->device_code,
                $clash->reference(),
                $clash->submitted_at?->toDayDateTimeString() ?? 'earlier'
            ), 409);
        }

        if ($prepared['rows'] === []) {
            throw new BusinessRuleException(sprintf(
                'None of the %d row(s) in this file could be used, so nothing has been imported.',
                count($prepared['errors'])
            ));
        }

        return DB::transaction(function () use ($prepared, $user, $device, $file) {
            $shop = $prepared['shop'];

            $audit = Audit::create([
                'shop_id' => $shop->id,
                'device_id' => $device->id,
                'audit_number' => $prepared['audit_number'],
                'audit_ref' => $prepared['reference'],
                'audit_date' => $prepared['audit_date'],
                'hht_user' => $prepared['counted_by'],
                'submitted_at' => now(),
                'item_count' => count($prepared['rows']),
                'status' => Audit::STATUS_SUBMITTED,
                'source' => Audit::SOURCE_EXCEL,
            ]);

            $now = now();
            $varianceCount = 0;
            $buffer = [];

            foreach ($prepared['rows'] as $row) {
                if ((float) $row['variance_qty'] != 0.0) {
                    $varianceCount++;
                }

                $buffer[] = [
                    'audit_id' => $audit->id,
                    'shop_id' => $shop->id,
                    'item_stock_id' => $row['item_stock_id'],
                    'product_code' => $row['product_code'],
                    'barcode' => $row['barcode'],
                    'description' => $row['description'],
                    'system_qty' => $row['system_qty'],
                    'source_system_qty' => $row['source_system_qty'],
                    'physical_qty' => $row['physical_qty'],
                    'loose_qty' => $row['loose_qty'],
                    'variance_qty' => $row['variance_qty'],
                    'uom' => $row['uom'],
                    'price' => $row['price'],
                    'batch' => $row['batch'],
                    'expiry_date' => $row['expiry_date'],
                    'shelf_location' => null,
                    'is_unknown_item' => $row['item_stock_id'] === null,
                    'verification_status' => AuditLine::VERIFICATION_PENDING,
                    'adjustment_status' => AuditLine::ADJUSTMENT_NOT_ADJUSTED,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($buffer) === self::INSERT_CHUNK) {
                    AuditLine::insert($buffer);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                AuditLine::insert($buffer);
            }

            $audit->update(['variance_count' => $varianceCount]);

            // The same ledger the JSON endpoint uses, so both routes are
            // idempotent by the same mechanism and visible in one place.
            HhtSubmission::create([
                'submission_uid' => $prepared['fingerprint'],
                'shop_id' => $shop->id,
                'device_id' => $device->id,
                'audit_number' => $prepared['audit_number'],
                'audit_date' => $prepared['audit_date'],
                'hht_user' => $prepared['counted_by'],
                'item_count' => count($prepared['rows']),
                'payload_hash' => $prepared['fingerprint'],
                'status' => HhtSubmission::STATUS_ACCEPTED,
                'message' => sprintf('Imported from %s.', $file->getClientOriginalName()),
                'audit_id' => $audit->id,
                'received_at' => now(),
            ]);

            $device->update(['last_submission_at' => now()]);

            activity('hht_import')
                ->causedBy($user)
                ->performedOn($audit)
                ->withProperties([
                    'file' => $file->getClientOriginalName(),
                    'reference' => $prepared['reference'],
                    'shop' => $shop->shop_code,
                    'device' => $device->device_code,
                    'imported' => count($prepared['rows']),
                    'rejected' => count($prepared['errors']),
                ])
                ->log('Audit imported from a handheld export');

            return [
                'audit' => $audit->fresh(['shop', 'device']),
                'duplicate' => false,
                'summary' => [
                    'reference' => $prepared['reference'],
                    'imported' => count($prepared['rows']),
                    'rejected' => count($prepared['errors']),
                    'unmatched_locations' => $prepared['unmatched'],
                    'items_unmatched' => count(array_filter($prepared['rows'], fn ($r) => $r['item_stock_id'] === null)),
                    'lines_with_loose' => count(array_filter($prepared['rows'], fn ($r) => (float) $r['loose_qty'] > 0)),
                    'system_qty_disagreements' => $prepared['drift'],
                    'variance_disagreements' => $prepared['variance_mismatch'],
                    'variance_lines' => $varianceCount,
                    'action' => 'created',
                ],
            ];
        });
    }

    /**
     * Reads and validates, touching nothing.
     *
     * @return array<string, mixed>
     */
    private function prepare(UploadedFile $file, ?Device $device): array
    {
        $path = $file->getRealPath();
        $detected = $this->reader->detect($path);

        if ($detected['kind'] !== HhtExcelReader::KIND_AUDIT) {
            throw new BusinessRuleException(
                'This is a stock take export, not an audit. Import it from the Stock Take side so its counts are not '
                .'measured against a system quantity it never carried.'
            );
        }

        $identity = $this->reader->identify($path, $detected['sheet'], $detected['headings'], $detected['kind']);
        $shops = $this->shopsByLocation();
        $resolved = $this->resolveShop($identity['locations'], $shops, $identity['reference']);
        $shop = $resolved['shop'];

        if ($device && $device->shop_id !== $shop->id) {
            throw new BusinessRuleException(sprintf(
                'Device %s belongs to a different shop from the one this file covers (%s).',
                $device->device_code,
                $shop->shop_code
            ));
        }

        $rows = [];
        $errors = [];
        $seen = [];
        $matchedOn = ['gtin' => 0, 'product_code' => 0, 'barcode' => 0, 'none' => 0];
        $drift = 0;
        $varianceMismatch = 0;
        $countedBy = null;
        $countedDate = null;

        $this->reader->eachRow($path, $detected['sheet'], $detected['headings'], function (array $row, int $rowNumber) use (
            &$rows, &$errors, &$seen, &$matchedOn, &$drift, &$varianceMismatch, &$countedBy, &$countedDate, $shop, $shops
        ) {
            $location = strtoupper($this->text($row['inventlocationid'] ?? null));
            $itemId = $this->text($row['itemid'] ?? null);
            $rawPhysical = $row['physicalqty'] ?? null;

            // A blank line at the end of a sheet is not an error.
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

            if ($itemId === '' && $this->text($row['itembarcode'] ?? null) === '') {
                $errors[] = $this->error($rowNumber, 'ITEMID', null, 'A row needs an item code or a scanned code.');

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

            $key = $itemId.'|'.$batch;

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

            $physical = round((float) $rawPhysical, 3);
            $loose = is_numeric($rawLoose) ? round((float) $rawLoose, 3) : 0.0;

            // PharmaVerify's own figure is authoritative. The file's is kept
            // beside it so the two can be compared rather than one silently
            // winning.
            $systemQty = $stock ? (float) $stock->system_qty : 0.0;
            $rawSystem = $row['systemqty'] ?? null;
            $sourceSystem = is_numeric($rawSystem) ? round((float) $rawSystem, 3) : null;

            if ($sourceSystem !== null && abs($sourceSystem - $systemQty) > 0.0005) {
                $drift++;
            }

            $variance = AuditLine::calculateVariance($physical, $loose, $systemQty);

            // The device computes the negation of our convention, so its figure
            // should equal -variance. Anything else means the two sides were
            // working from different system quantities.
            $rawVariance = $row['variance'] ?? null;

            if (is_numeric($rawVariance) && abs(round((float) $rawVariance, 3) + $variance) > 0.0005) {
                $varianceMismatch++;
            }

            if ($countedBy === null) {
                $countedBy = $this->text($row['verifiedby'] ?? null) ?: null;
            }

            if ($countedDate === null) {
                $countedDate = $this->parseDate($row['verifieddate'] ?? null);
            }

            $rows[] = [
                'item_stock_id' => $stock?->id,
                'product_code' => $itemId !== '' ? $itemId : $stock?->product_code,
                'barcode' => $scanned !== '' ? $scanned : $stock?->barcode,
                'description' => $stock?->description ?? ($this->text($row['itemname'] ?? null) ?: 'Unknown product'),
                'system_qty' => $systemQty,
                'source_system_qty' => $sourceSystem,
                'physical_qty' => $physical,
                'loose_qty' => $loose,
                'variance_qty' => $variance,
                'uom' => $stock?->uom ?? 'EA',
                'price' => (float) ($stock?->price ?? 0),
                'batch' => $batch,
                'expiry_date' => $expiry ?? $stock?->expiry_date?->toDateString(),
            ];
        });

        $sequence = SessionReference::sequenceOf($identity['reference']);
        $parsed = SessionReference::parse($identity['reference']);

        return [
            'reference' => $identity['reference'],
            'audit_number' => $sequence,
            'audit_date' => $countedDate ?? Carbon::createFromFormat('dmY', $parsed['date'])->toDateString(),
            'counted_by' => $countedBy,
            'shop' => $shop,
            'unmatched' => $resolved['unmatched'],
            'rows' => $rows,
            'errors' => $errors,
            'matched_on' => $matchedOn,
            'drift' => $drift,
            'variance_mismatch' => $varianceMismatch,
            'fingerprint' => $this->fingerprint($shop->shop_code, $identity['reference'], $rows),
        ];
    }

    /**
     * @param  array<string, mixed>  $prepared
     */
    private function existingAudit(array $prepared): ?Audit
    {
        return Audit::where('shop_id', $prepared['shop']->id)
            ->where('audit_ref', $prepared['reference'])
            ->first();
    }

    /**
     * An audit already holding this shop, device and number.
     *
     * @param  array<string, mixed>  $prepared
     */
    private function existingByIdentity(array $prepared, Device $device): ?Audit
    {
        return Audit::where('shop_id', $prepared['shop']->id)
            ->where('device_id', $device->id)
            ->where('audit_number', $prepared['audit_number'])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $prepared
     */
    private function existingSubmission(array $prepared): ?HhtSubmission
    {
        return HhtSubmission::where('shop_id', $prepared['shop']->id)
            ->where('payload_hash', $prepared['fingerprint'])
            ->whereNotNull('audit_id')
            ->first();
    }
}
