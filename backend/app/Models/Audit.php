<?php

namespace App\Models;

use App\Models\Concerns\ScopesToUserShops;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A completed HHT stock count.
 *
 * Identity is Shop + Device + Audit Number. The audit number alone repeats
 * across the devices of a shop and is never treated as unique on its own.
 */
class Audit extends Model
{
    use ScopesToUserShops;

    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_IN_VERIFICATION = 'in_verification';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_ADJUSTED = 'adjusted';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'shop_id',
        'device_id',
        'audit_number',
        'audit_date',
        'hht_user',
        'submitted_at',
        'item_count',
        'variance_count',
        'status',
        'verified_by',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'audit_date' => 'date',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AuditLine::class);
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(HhtSubmission::class, 'id', 'audit_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(StockAdjustment::class);
    }

    public function finalOutputs(): HasMany
    {
        return $this->hasMany(FinalOutput::class);
    }

    /** Human readable identity, e.g. "PHM001 / HHT-02 / Audit 3". */
    public function getReferenceAttribute(): string
    {
        return sprintf(
            '%s / %s / Audit %d',
            $this->shop?->shop_code ?? '-',
            $this->device?->device_code ?? '-',
            $this->audit_number
        );
    }
}
