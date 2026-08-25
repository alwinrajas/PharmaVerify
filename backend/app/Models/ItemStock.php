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
        'description',
        'system_qty',
        'uom',
        'price',
        'batch',
        'expiry_date',
        'shelf_location',
        'verification_status',
    ];

    protected function casts(): array
    {
        return [
            'system_qty' => 'decimal:3',
            'price' => 'decimal:4',
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
