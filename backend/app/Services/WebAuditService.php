<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Audit;
use App\Models\AuditLine;
use App\Models\ItemStock;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Counting a shelf from the browser.
 *
 * The handheld route already exists and is not touched: a device posts a
 * finished count and HhtSubmissionService writes it in one go. This is the
 * other way in — a person at a keyboard, counting over minutes, one product at
 * a time, with the audit growing as they go.
 *
 * Everything downstream is deliberately shared. A browser audit becomes the
 * same Audit rows and AuditLine rows a handheld produces, so variance,
 * adjustment, the ledger and every report keep working without knowing which
 * route a count arrived by. The only difference recorded is `source`, and the
 * absence of a device.
 */
class WebAuditService
{
    /**
     * Opens an audit for a shop and hands back the empty shell to count into.
     *
     * The audit number continues the shop's own series rather than starting a
     * private one for the browser: a pharmacy refers to "audit 14" without
     * caring how it was counted, and two numbering schemes in one shop would
     * make that reference ambiguous.
     *
     * There is deliberately no free-text reference parameter here. The audits
     * table has no column to keep one in, and accepting a value only to drop
     * it would be worse than not offering it — the operator would believe the
     * shelf label they typed had been recorded.
     */
    public function start(Shop $shop, User $user): Audit
    {
        return DB::transaction(function () use ($shop, $user) {
            $open = Audit::where('shop_id', $shop->id)
                ->where('status', Audit::STATUS_IN_PROGRESS)
                ->whereNull('device_id')
                ->first();

            if ($open) {
                // Resumed rather than replaced. The half-counted shelf in the
                // open audit is work somebody already did, and starting a
                // second one beside it would split one count across two
                // records that each look complete.
                return $open;
            }

            $auditNumber = $this->nextAuditNumber($shop);

            return Audit::create([
                'shop_id' => $shop->id,
                'device_id' => null,
                'audit_number' => $auditNumber,
                'audit_date' => now()->toDateString(),
                'hht_user' => $user->name,
                'submitted_at' => null,
                'item_count' => 0,
                'variance_count' => 0,
                'status' => Audit::STATUS_IN_PROGRESS,
                'source' => Audit::SOURCE_SYSTEM,
            ]);
        });
    }

    /**
     * The next free number in this shop's series.
     *
     * Taken inside the caller's transaction and with the shop's audits locked,
     * because two people starting a count in the same shop at the same moment
     * would otherwise read the same maximum and both claim it.
     */
    private function nextAuditNumber(Shop $shop): int
    {
        $highest = Audit::where('shop_id', $shop->id)
            ->lockForUpdate()
            ->max('audit_number');

        return (int) $highest + 1;
    }

    /**
     * Everything the shop holds under one barcode or product code.
     *
     * Scoped to the shop on purpose. The same product sits in several shops
     * with different quantities, and a lookup that ignored the shop would show
     * an operator another branch's stock — a number they would then count
     * against, producing a variance that describes nothing real.
     *
     * Returns every matching batch rather than picking one. Which physical box
     * is in the operator's hand is a fact only they can supply.
     */
    public function findStock(Shop $shop, string $code): Collection
    {
        $needle = trim($code);

        if ($needle === '') {
            return new Collection();
        }

        return ItemStock::query()
            ->where('shop_id', $shop->id)
            ->where(function ($query) use ($needle) {
                $query->where('barcode', $needle)
                    ->orWhere('gtin', $needle)
                    ->orWhere('product_code', $needle);
            })
            ->orderBy('expiry_date')
            ->orderBy('batch')
            ->get();
    }

    /**
     * Records one counted product against an open audit.
     *
     * Counting the same batch twice in one audit updates the line rather than
     * adding a second: two lines for one batch make the audit's totals wrong
     * in a way nobody notices until the variance is already posted.
     */
    public function recordCount(
        Audit $audit,
        ItemStock $stock,
        float $physicalQty,
        float $looseQty,
        User $user
    ): AuditLine {
        if ($audit->status !== Audit::STATUS_IN_PROGRESS) {
            throw new BusinessRuleException('This audit has been completed and can no longer be counted into.');
        }

        if ($stock->shop_id !== $audit->shop_id) {
            // The lookup is shop-scoped, so this only fires if a request was
            // built by hand. It is checked anyway: a count filed against
            // another shop's stock would adjust the wrong branch's books.
            throw new BusinessRuleException('That product belongs to a different shop and cannot be counted into this audit.');
        }

        if ($physicalQty < 0 || $looseQty < 0) {
            throw new BusinessRuleException('A counted quantity cannot be negative.');
        }

        return DB::transaction(function () use ($audit, $stock, $physicalQty, $looseQty, $user) {
            $systemQty = (float) $stock->system_qty;

            $line = AuditLine::where('audit_id', $audit->id)
                ->where('item_stock_id', $stock->id)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'audit_id' => $audit->id,
                'shop_id' => $audit->shop_id,
                'item_stock_id' => $stock->id,
                'product_code' => $stock->product_code,
                'barcode' => $stock->barcode,
                'description' => $stock->description,
                // The system figure is copied as it stands at the moment of
                // counting, not read live afterwards. It is what the operator
                // was counting against, and the variance has to be explainable
                // in those terms months later.
                'system_qty' => $systemQty,
                'physical_qty' => $physicalQty,
                'loose_qty' => $looseQty,
                'variance_qty' => AuditLine::calculateVariance($physicalQty, $looseQty, $systemQty),
                'uom' => $stock->uom,
                'price' => $stock->price,
                'batch' => $stock->batch,
                'expiry_date' => $stock->expiry_date,
                'shelf_location' => $stock->shelf_location,
                'is_unknown_item' => false,
                'verification_status' => AuditLine::VERIFICATION_PENDING,
                'verified_by' => $user->id,
                'verified_at' => now(),
            ];

            if ($line) {
                $line->update($attributes);
            } else {
                $line = AuditLine::create($attributes);
            }

            $this->refreshTotals($audit);

            return $line->fresh();
        });
    }

    /** Removes a counted line from an open audit. */
    public function removeCount(Audit $audit, AuditLine $line): void
    {
        if ($audit->status !== Audit::STATUS_IN_PROGRESS) {
            throw new BusinessRuleException('This audit has been completed and can no longer be changed.');
        }

        if ($line->audit_id !== $audit->id) {
            throw new BusinessRuleException('That counted line belongs to another audit.');
        }

        DB::transaction(function () use ($audit, $line) {
            $line->delete();
            $this->refreshTotals($audit);
        });
    }

    /**
     * Closes the audit so its variances can be adjusted.
     *
     * An empty audit is refused: it would appear in the review list as a
     * finished count of nothing, which is indistinguishable from a shelf that
     * was genuinely counted and found bare.
     */
    public function complete(Audit $audit, User $user): Audit
    {
        if ($audit->status !== Audit::STATUS_IN_PROGRESS) {
            throw new BusinessRuleException('This audit has already been completed.');
        }

        return DB::transaction(function () use ($audit, $user) {
            $lineCount = AuditLine::where('audit_id', $audit->id)->count();

            if ($lineCount === 0) {
                throw new BusinessRuleException('Count at least one product before completing this audit.');
            }

            $this->refreshTotals($audit);

            $audit->forceFill([
                'status' => Audit::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'hht_user' => $audit->hht_user ?: $user->name,
            ])->save();

            return $audit->fresh();
        });
    }

    /**
     * Recomputes the audit's own tallies from its lines.
     *
     * Derived rather than incremented: a running counter drifts the moment a
     * line is edited or removed, and these totals are what the review screen
     * and the reports show.
     */
    private function refreshTotals(Audit $audit): void
    {
        $lines = AuditLine::where('audit_id', $audit->id)->get();

        $audit->forceFill([
            'item_count' => $lines->count(),
            'variance_count' => $lines->filter(fn ($line) => (float) $line->variance_qty !== 0.0)->count(),
        ])->save();
    }
}
