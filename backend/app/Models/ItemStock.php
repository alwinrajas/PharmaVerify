<?php

namespace App\Models;

use App\Models\Concerns\ScopesToUserShops;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemStock extends Model
{
    use HasFactory;
    use ScopesToUserShops;

    protected $fillable = [
        'shop_id',
        'item_id',
        'stock_import_id',
        'product_code',
        'barcode',
        'gtin',
        'description',
        'system_qty',
        'whole_qty',
        'factor',
        'uom',
        'price',
        'total_cost',
        'batch',
        'expiry_date',
        'shelf_location',
        'verification_status',
    ];

    protected function casts(): array
    {
        return [
            'system_qty' => 'decimal:3',
            // Whole quantity is fractional in the source data and must keep its
            // decimals — it is a pack count, not a whole number.
            'whole_qty' => 'decimal:4',
            'factor' => 'decimal:4',
            'price' => 'decimal:4',
            'total_cost' => 'decimal:4',
            'expiry_date' => 'date',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function stockImport(): BelongsTo
    {
        return $this->belongsTo(StockImport::class);
    }
}
