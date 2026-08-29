<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Audit;
use App\Models\AuditLine;
use App\Models\Device;
use App\Models\HhtSubmission;
use App\Models\ItemStock;
use App\Models\Shop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Receives a completed stock count from an HHT device.
 *
 * The device holds every scan in its own storage while counting; nothing
 * reaches the server until the operator taps Share / Submit, at which point the
 * whole audit arrives in a single call. This service is that entry point.
 *
 * Submission identity is Shop + Device + Audit Number. The audit number on its
 * own is not unique: several devices in one shop routinely carry the same
 * number, and each device advances its own sequence.
 */
class HhtSubmissionService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{submission: HhtSubmission, audit: Audit, duplicate: bool}
     */
    public function receive(array $payload): array
    {
        $shop = $this->resolveShop($payload);
        $device = $this->resolveDevice($shop, $payload);

        $auditNumber = (int) $payload['audit_number'];
        $submissionUid = (string) ($payload['submission_uid'] ?? '');
        $items = $payload['items'];
        $payloadHash = hash('sha256', json_encode([
            'shop' => $shop->id,
            'device' => $device->id,
            'audit_number' => $auditNumber,
            'items' => $items,
        ]));

        // Idempotency. A retry after a dropped connection must not produce a
        // second audit, so an identical submission is acknowledged and ignored.
        $existing = $this->findExistingSubmission($shop->id, $device->id, $auditNumber, $submissionUid, $payloadHash);

        if ($existing && $existing->audit) {
            return [
                'submission' => $existing,
                'audit' => $existing->audit,
                'duplicate' => true,
            ];
        }

        return DB::transaction(function () use ($shop, $device, $auditNumber, $submissionUid, $payloadHash, $payload, $items) {
            $audit = Audit::where('shop_id', $shop->id)
                ->where('device_id', $device->id)
                ->where('audit_number', $auditNumber)
                ->first();

            if ($audit) {
                throw new BusinessRuleException(sprintf(
                    'Audit %d has already been submitted from device %s at %s. Use a new audit number for a fresh count.',
                    $auditNumber,
                    $device->device_code,
                    $shop->shop_code
                ), 409);
            }

            $auditDate = Carbon::parse($payload['audit_date'] ?? now());

            $audit = Audit::create([
                'shop_id' => $shop->id,
                'device_id' => $device->id,
                'audit_number' => $auditNumber,
                'audit_date' => $auditDate->toDateString(),
                'hht_user' => $payload['hht_user'] ?? null,
                'submitted_at' => now(),
                'item_count' => count($items),
                'status' => Audit::STATUS_SUBMITTED,
                // Stated rather than left to the column default. The web tells
                // a direct submission from an imported spreadsheet by this
                // field alone, and a default is the wrong thing to rest that on
                // — the Excel importer sets its own, so only this path would be
                // relying on one.
                'source' => Audit::SOURCE_API,
            ]);

            $varianceCount = $this->createLines($audit, $shop, $items);

            $audit->update(['variance_count' => $varianceCount]);

            $submission = HhtSubmission::create([
                'submission_uid' => $submissionUid !== '' ? $submissionUid : sprintf('SUB-%s-%s-%d', $shop->shop_code, $device->device_code, $auditNumber),
                'shop_id' => $shop->id,
                'device_id' => $device->id,
                'audit_number' => $auditNumber,
                'audit_date' => $auditDate->toDateString(),
                'hht_user' => $payload['hht_user'] ?? null,
                'app_version' => $payload['app_version'] ?? null,
                'item_count' => count($items),
                'payload_hash' => $payloadHash,
                'status' => HhtSubmission::STATUS_ACCEPTED,
                'message' => 'Submission accepted and audit created.',
                'audit_id' => $audit->id,
                'received_at' => now(),
            ]);

            $device->update(['last_submission_at' => now()]);

            return [
                'submission' => $submission,
                'audit' => $audit->fresh(['shop', 'device']),
                'duplicate' => false,
            ];
        });
    }

    /**
     * Creates one audit line per counted product and returns how many of them
     * carry a variance.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function createLines(Audit $audit, Shop $shop, array $items): int
    {
        $varianceCount = 0;
        $rows = [];
        $now = now();

        foreach ($items as $item) {
            $stock = $this->matchStock($shop->id, $item);

            $physicalQty = (float) ($item['physical_quantity'] ?? 0);
            $looseQty = (float) ($item['loose_quantity'] ?? 0);
            $systemQty = $stock ? (float) $stock->system_qty : 0.0;
            $variance = AuditLine::calculateVariance($physicalQty, $looseQty, $systemQty);

            if ($variance != 0.0) {
                $varianceCount++;
            }

            $rows[] = [
                'audit_id' => $audit->id,
                'shop_id' => $shop->id,
                'item_stock_id' => $stock?->id,
                'product_code' => $item['product_code'] ?? $stock?->product_code,
                'barcode' => $item['barcode'] ?? $stock?->barcode,
                'description' => $stock?->description ?? ($item['description'] ?? 'Unknown product'),
                'system_qty' => $systemQty,
                'physical_qty' => $physicalQty,
                'loose_qty' => $looseQty,
                'variance_qty' => $variance,
                'uom' => $item['uom'] ?? $stock?->uom ?? 'EA',
                // Cast explicitly: in a multi-row insert every row must give a
                // column the same PHP type, or SQL Server infers the parameter
                // type from one row and rejects the others.
                'price' => (float) ($stock?->price ?? 0),
                'batch' => (string) ($item['batch'] ?? $stock?->batch ?? ''),
                'expiry_date' => $this->parseDate($item['expiry'] ?? $item['expiry_date'] ?? null)
                    ?? $stock?->expiry_date?->toDateString(),
                'shelf_location' => $item['shelf_location'] ?? $stock?->shelf_location,
                'is_unknown_item' => $stock === null,
                'verification_status' => AuditLine::VERIFICATION_PENDING,
                'adjustment_status' => AuditLine::ADJUSTMENT_NOT_ADJUSTED,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            AuditLine::insert($chunk);
        }

        return $varianceCount;
    }

    /**
     * Finds the stock line a counted item refers to.
     *
     * The GTIN is the identifier the handheld scans, so it is tried first,
     * whether it arrives in its own field or in the `barcode` field an older
     * device sends. The 7-digit internal barcode is a different identifier
     * system and is only consulted once the GTIN has found nothing, so it can
     * never displace the GTIN as the primary match.
     *
     * @param  array<string, mixed>  $item
     */
    private function matchStock(int $shopId, array $item): ?ItemStock
    {
        $scanned = $item['gtin'] ?? $item['barcode'] ?? null;

        $candidates = [];

        if (! empty($scanned)) {
            $candidates[] = ['gtin', $scanned];
        }

        if (! empty($item['product_code'])) {
            $candidates[] = ['product_code', $item['product_code']];
        }

        if (! empty($item['barcode'])) {
            $candidates[] = ['barcode', $item['barcode']];
        }

        foreach ($candidates as [$column, $value]) {
            $query = ItemStock::where('shop_id', $shopId)->where($column, $value);

            // Batch narrows the match; without one, the earliest expiry is the
            // stock a shelf is worked from.
            if (! empty($item['batch'])) {
                $batchMatch = (clone $query)->where('batch', $item['batch'])->first();

                if ($batchMatch) {
                    return $batchMatch;
                }
            }

            $match = $query->orderBy('expiry_date')->first();

            if ($match) {
                return $match;
            }
        }

        return null;
    }

    private function parseDate(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveShop(array $payload): Shop
    {
        $shop = isset($payload['shop_id'])
            ? Shop::find($payload['shop_id'])
            : Shop::where('shop_code', $payload['shop_code'] ?? null)->first();

        if (! $shop) {
            throw new BusinessRuleException('The shop in this submission could not be recognised.', 422);
        }

        if ($shop->status !== 'active') {
            throw new BusinessRuleException(sprintf('Shop %s is not active and cannot accept submissions.', $shop->shop_code), 422);
        }

        return $shop;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveDevice(Shop $shop, array $payload): Device
    {
        $device = isset($payload['device_id'])
            ? Device::where('shop_id', $shop->id)->find($payload['device_id'])
            : Device::where('shop_id', $shop->id)->where('device_code', $payload['device_code'] ?? null)->first();

        if (! $device) {
            throw new BusinessRuleException(sprintf(
                'The device in this submission is not registered against shop %s.',
                $shop->shop_code
            ), 422);
        }

        if ($device->status !== 'active') {
            throw new BusinessRuleException(sprintf('Device %s is not active and cannot submit a count.', $device->device_code), 422);
        }

        return $device;
    }

    private function findExistingSubmission(
        int $shopId,
        int $deviceId,
        int $auditNumber,
        string $submissionUid,
        string $payloadHash
    ): ?HhtSubmission {
        $query = HhtSubmission::with('audit')
            ->where('shop_id', $shopId)
            ->where('device_id', $deviceId)
            ->where('audit_number', $auditNumber);

        if ($submissionUid !== '') {
            $match = (clone $query)->where('submission_uid', $submissionUid)->first();

            if ($match) {
                return $match;
            }
        }

        return $query->where('payload_hash', $payloadHash)->first();
    }
}
