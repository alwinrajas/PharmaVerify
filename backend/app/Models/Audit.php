<?php

namespace App\Models;

use App\Models\Concerns\ScopesToUserShops;
use App\Support\SessionReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A completed HHT stock count.
 *
 * Identity is Shop + Device + Audit Number. The audit number alone repeats
 * across the devices of a shop and is never treated as unique on its own.
 *
 * `audit_ref` carries the handheld's own reference, `AUD-ddMMyyyy-NNNN`. It is
 * display and matching information rather than identity: the device numbers per
 * shop, PharmaVerify per shop and device, so one reference can legitimately
 * belong to several audits of the same shop taken on different devices.
 */
class Audit extends Model
{
    use ScopesToUserShops;

    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_IN_VERIFICATION = 'in_verification';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_ADJUSTED = 'adjusted';
    public const STATUS_CLOSED = 'closed';

    /** Arrived through the HHT JSON endpoint. */
    public const SOURCE_API = 'api';

    /** Read from a workbook exported by a handheld. */
    public const SOURCE_EXCEL = 'excel';

    protected $fillable = [
        'shop_id',
        'device_id',
        'audit_number',
        'audit_ref',
        'audit_date',
        'hht_user',
        'submitted_at',
        'item_count',
        'variance_count',
        'status',
        'source',
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

    /**
     * The reference to show for this audit.
     *
     * The handheld's own where there is one, and otherwise one derived from the
     * date and number so a screen never has to choose between two formats. The
     * fallback is permanent, not a stopgap for the backfill: an audit created
     * through the JSON endpoint has no device reference and never will.
     */
    public function reference(): string
    {
        if (filled($this->audit_ref)) {
            return (string) $this->audit_ref;
        }

        return SessionReference::forAudit(
            $this->audit_date ?? $this->created_at ?? now(),
            (int) $this->audit_number
        );
    }

    /**
     * Is this reference already on another audit of the same shop?
     *
     * The database index is not unique — historical rows legitimately repeat a
     * reference across devices — so an importer asks this before writing, and
     * can then say which audit it clashed with rather than surfacing a
     * constraint error.
     */
    public static function refTakenAtShop(int $shopId, string $reference, ?int $ignoreId = null): bool
    {
        return static::where('shop_id', $shopId)
            ->where('audit_ref', $reference)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
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
