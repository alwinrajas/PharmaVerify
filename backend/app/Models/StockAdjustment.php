<?php

namespace App\Models;

use App\Models\Concerns\ScopesToUserShops;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * History of an adjustment that has already been posted.
 *
 * Adjustments take effect the moment they are saved; there is no approval
 * workflow, so this table is a record of what happened, never a queue.
 */
class StockAdjustment extends Model
{
    use ScopesToUserShops;

    protected $fillable = [
        'audit_line_id',
        'audit_id',
        'shop_id',
        'item_stock_id',
        'product_code',
        'barcode',
        'description',
        'batch',
        'old_system_qty',
        'physical_qty',
        'variance_qty',
        'new_system_qty',
        'reason',
        'adjusted_by',
        'adjusted_at',
    ];

    protected function casts(): array
    {
        return [
            'old_system_qty' => 'decimal:3',
            'physical_qty' => 'decimal:3',
            'variance_qty' => 'decimal:3',
            'new_system_qty' => 'decimal:3',
            'adjusted_at' => 'datetime',
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

    public function auditLine(): BelongsTo
    {
        return $this->belongsTo(AuditLine::class);
    }

    public function adjustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }
}
