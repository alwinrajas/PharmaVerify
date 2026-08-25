<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Item extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'product_code',
        'barcode',
        'description',
        'generic_name',
        'manufacturer',
        'uom',
        'price',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return ['price' => 'decimal:4'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['product_code', 'barcode', 'description', 'generic_name', 'manufacturer', 'uom', 'price', 'status'])
            ->logOnlyDirty()
            ->useLogName('item')
            ->dontSubmitEmptyLogs();
    }

    public function itemStocks(): HasMany
    {
        return $this->hasMany(ItemStock::class);
    }
}
