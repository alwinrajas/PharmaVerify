<?php

namespace App\Models;

use App\Models\Concerns\ScopesToUserShops;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Physical stock found on the shelf that the shop's stock data does not carry.
 *
 * Recording a stock take never creates an Item Master record; the entry stays
 * here until the business decides what to do with it.
 */
class StockTake extends Model
{
    use ScopesToUserShops;

    protected $fillable = [
        'shop_id',
        'audit_id',
        'audit_line_id',
        'barcode',
        'product_code',
        'description',
        'physical_qty',
        'uom',
        'batch',
        'expiry_date',
        'shelf_location',
        'status',
        'remarks',
        'taken_by',
        'taken_at',
    ];

    protected function casts(): array
    {
        return [
            'physical_qty' => 'decimal:3',
            'expiry_date' => 'date',
            'taken_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    public function takenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by');
    }
}
