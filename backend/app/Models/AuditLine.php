<?php

namespace App\Models;

use App\Models\Concerns\ScopesToUserShops;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One counted product inside an audit.
 *
 * Verification state and variance both live here rather than in separate
 * tables: variance is nothing more than physical minus system, and a
 * verification record would carry no field the line does not already hold.
 */
class AuditLine extends Model
{
    use ScopesToUserShops;

    public const VERIFICATION_PENDING = 'pending';
    public const VERIFICATION_VERIFIED = 'verified';

    public const ADJUSTMENT_NOT_ADJUSTED = 'not_adjusted';
    public const ADJUSTMENT_ADJUSTED = 'adjusted';

    protected $fillable = [
        'audit_id',
        'shop_id',
        'item_stock_id',
        'product_code',
        'barcode',
        'description',
        'system_qty',
        'physical_qty',
        'variance_qty',
        'uom',
        'price',
        'batch',
        'expiry_date',
        'shelf_location',
        'is_unknown_item',
        'verification_status',
        'adjustment_status',
        'verified_by',
        'verified_at',
        'adjusted_at',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'system_qty' => 'decimal:3',
            'physical_qty' => 'decimal:3',
            'variance_qty' => 'decimal:3',
            'price' => 'decimal:4',
            'expiry_date' => 'date',
            'is_unknown_item' => 'boolean',
            'verified_at' => 'datetime',
            'adjusted_at' => 'datetime',
        ];
    }

    /** Variance = Physical Quantity - System Quantity. */
    public static function calculateVariance(float $physicalQty, float $systemQty): float
    {
        return round($physicalQty - $systemQty, 3);
    }

    public function recalculateVariance(): void
    {
        $this->variance_qty = self::calculateVariance(
            (float) $this->physical_qty,
            (float) $this->system_qty
        );
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function itemStock(): BelongsTo
    {
        return $this->belongsTo(ItemStock::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(StockAdjustment::class);
    }

    public function scopeVarianceDirection(Builder $query, ?string $direction): Builder
    {
        return match ($direction) {
            'positive' => $query->where('variance_qty', '>', 0),
            'negative' => $query->where('variance_qty', '<', 0),
            'zero' => $query->where('variance_qty', '=', 0),
            'non_zero' => $query->where('variance_qty', '!=', 0),
            default => $query,
        };
    }
}
