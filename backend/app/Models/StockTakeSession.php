<?php

namespace App\Models;

use App\Models\Concerns\ScopesToUserShops;
use App\Support\SessionReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One stock-take cycle for one shop.
 *
 * A shop is swept repeatedly over its life and each sweep is its own cycle with
 * its own reference and its own lines. Modelling the cycle as a row — rather
 * than leaving counted lines loose — is what lets an earlier take stay readable
 * after a later one has started.
 *
 * Withdrawing a cycle is a soft delete. The row and everything counted inside
 * it survive, so the reference it consumed is never reissued and the counts
 * stay readable as history.
 */
class StockTakeSession extends Model
{
    use ScopesToUserShops;
    use SoftDeletes;

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const SOURCE_WEB = 'web';

    public const SOURCE_EXCEL = 'excel';

    protected $fillable = [
        'shop_id',
        'take_ref',
        'take_number',
        'take_date',
        'status',
        'item_count',
        'source',
        'payload_hash',
        'file_name',
        'counted_by_name',
        'created_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'take_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockTake::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The next sequence this shop would issue.
     *
     * Counts withdrawn cycles as well as live ones. A number is evidence that a
     * count happened, and handing 0007 to a second sweep because the first was
     * withdrawn would make the two indistinguishable in a report.
     */
    public static function nextNumberFor(int $shopId): int
    {
        return (int) static::withTrashed()->where('shop_id', $shopId)->max('take_number') + 1;
    }

    /**
     * Raises the next cycle for a shop, numbered the way the handheld numbers.
     */
    public static function openFor(int $shopId, ?User $user = null, Carbon|string|null $date = null): self
    {
        $on = $date instanceof Carbon ? $date : Carbon::parse($date ?? now());
        $sequence = static::nextNumberFor($shopId);

        return static::create([
            'shop_id' => $shopId,
            'take_ref' => SessionReference::forStockTake($on, $sequence),
            'take_number' => $sequence,
            'take_date' => $on->toDateString(),
            'status' => self::STATUS_IN_PROGRESS,
            'source' => self::SOURCE_WEB,
            'created_by' => $user?->id,
        ]);
    }

    /** Keeps the recorded line count in step with the lines themselves. */
    public function refreshItemCount(): void
    {
        $this->update(['item_count' => $this->lines()->count()]);
    }
}
