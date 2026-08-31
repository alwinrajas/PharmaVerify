<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLine;
use App\Models\ItemStock;
use App\Models\StockAdjustment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Posts a stock adjustment.
 *
 * There is no approval step in this business: the moment an authorised user
 * saves the adjustment, the shop's system stock becomes the physical quantity
 * that was counted, and a history record is written describing what changed.
 */
class StockAdjustmentService
{
    public function __construct(private readonly VerificationService $verification) {}

    /**
     * Decimal(18,3) columns round-trip through floats, so an exact equality
     * check would flag rounding noise as drift. Anything smaller than this is
     * not a movement, it is arithmetic.
     */
    private const DRIFT_TOLERANCE = 0.0005;

    public function adjustLine(AuditLine $line, User $user, ?string $reason = null, bool $acknowledgeDrift = false): StockAdjustment
    {
        if ($line->adjustment_status === AuditLine::ADJUSTMENT_ADJUSTED) {
            throw new BusinessRuleException(sprintf(
                'Product %s in this audit has already been adjusted on %s.',
                $line->product_code,
                $line->adjusted_at?->format('d M Y H:i') ?? 'an earlier date'
            ));
        }

        if ($line->is_unknown_item || ! $line->item_stock_id) {
            throw new BusinessRuleException(
                'This product is not part of the shop stock and cannot be adjusted. Record it as a Stock Take instead.'
            );
        }

        return DB::transaction(function () use ($line, $user, $reason, $acknowledgeDrift) {
            /** @var ItemStock|null $stock */
            $stock = ItemStock::lockForUpdate()->find($line->item_stock_id);

            if (! $stock) {
                throw new BusinessRuleException('The stock record for this product no longer exists and cannot be adjusted.');
            }

            $oldQty = (float) $stock->system_qty;
            $auditedQty = (float) $line->system_qty;
            $physicalQty = (float) $line->physical_qty;
            $looseQty = (float) $line->loose_qty;

            // The count is still the truth, but if system stock has moved since
            // it was counted, someone else touched this stock in the meantime.
            // Overwriting that figure without the operator ever seeing it is
            // how a real movement quietly disappears, so it must be surfaced
            // and explicitly acknowledged rather than silently applied over.
            $drifted = abs($oldQty - $auditedQty) > self::DRIFT_TOLERANCE;

            if ($drifted && ! $acknowledgeDrift) {
                $countedPreview = AuditLine::countedTotal($physicalQty, $looseQty);

                throw new BusinessRuleException(sprintf(
                    'System stock for %s has changed since this audit was counted: the audit saw %s, stock now holds %s. Adjusting will replace %s with the counted %s. Confirm to proceed.',
                    $line->product_code,
                    self::trimmed($auditedQty),
                    self::trimmed($oldQty),
                    self::trimmed($oldQty),
                    self::trimmed($countedPreview)
                ));
            }

            $reason = $drifted
                ? trim(($reason !== null && $reason !== '' ? $reason.' ' : '').sprintf(
                    'Stock had changed from %s to %s before this adjustment.',
                    self::trimmed($auditedQty),
                    self::trimmed($oldQty)
                ))
                : $reason;

            // What the shelf actually holds: whole units plus loose. Posting
            // the physical figure alone would leave a residual variance equal
            // to the loose quantity, so the line below is what makes the
            // resulting variance genuinely zero rather than merely asserted.
            $countedQty = AuditLine::countedTotal($physicalQty, $looseQty);
            $variance = AuditLine::calculateVariance($physicalQty, $looseQty, $oldQty);

            // The count is the truth: system stock becomes what was counted.
            $stock->update([
                'system_qty' => $countedQty,
                'verification_status' => 'adjusted',
            ]);

            $adjustment = StockAdjustment::create([
                'audit_line_id' => $line->id,
                'audit_id' => $line->audit_id,
                'shop_id' => $line->shop_id,
                'item_stock_id' => $stock->id,
                'product_code' => $line->product_code,
                'barcode' => $line->barcode,
                'description' => $line->description,
                'batch' => $line->batch,
                'old_system_qty' => $oldQty,
                'physical_qty' => $physicalQty,
                'variance_qty' => $variance,
                'new_system_qty' => $countedQty,
                'reason' => $reason,
                'adjusted_by' => $user->id,
                'adjusted_at' => now(),
            ]);

            $line->forceFill([
                'system_qty' => $countedQty,
                'variance_qty' => 0,
                'adjustment_status' => AuditLine::ADJUSTMENT_ADJUSTED,
                'adjusted_at' => now(),
                'verification_status' => AuditLine::VERIFICATION_VERIFIED,
                'verified_by' => $line->verified_by ?? $user->id,
                'verified_at' => $line->verified_at ?? now(),
            ])->save();

            if ($line->audit) {
                $this->verification->refreshAuditCounters($line->audit);
            }

            activity('adjustment')
                ->causedBy($user)
                ->performedOn($adjustment)
                ->withProperties([
                    'shop_id' => $line->shop_id,
                    'audit_id' => $line->audit_id,
                    'product_code' => $line->product_code,
                    'batch' => $line->batch,
                    'old_system_qty' => $oldQty,
                    'new_system_qty' => $countedQty,
                    'variance_qty' => $variance,
                ])
                ->log('Stock adjustment posted');

            return $adjustment->fresh(['shop', 'audit.device', 'adjustedBy']);
        });
    }

    /**
     * Posts several lines in one action. Each line is adjusted individually so
     * that one refusal does not discard the rest of the batch.
     *
     * @param  array<int, int>  $lineIds
     * @return array{adjustments: Collection<int, StockAdjustment>, skipped: array<int, array<string, mixed>>}
     */
    public function adjustLines(array $lineIds, User $user, ?string $reason = null): array
    {
        $lines = AuditLine::with('audit')->whereIn('id', $lineIds)->get();
        $adjustments = collect();
        $skipped = [];

        foreach ($lines as $line) {
            try {
                $adjustments->push($this->adjustLine($line, $user, $reason));
            } catch (BusinessRuleException $e) {
                $skipped[] = [
                    'audit_line_id' => $line->id,
                    'product_code' => $line->product_code,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return ['adjustments' => $adjustments, 'skipped' => $skipped];
    }

    /**
     * A quantity for a user-facing sentence: 95.000 reads as 95, 95.500 as 95.5.
     */
    private static function trimmed(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
