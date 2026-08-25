<?php

namespace App\Models;

use App\Models\Concerns\ScopesToUserShops;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockImport extends Model
{
    use ScopesToUserShops;

    protected $fillable = [
        'shop_id',
        'file_name',
        'stored_path',
        'total_records',
        'success_records',
        'failed_records',
        'replaced_records',
        'status',
        'failure_reason',
        'imported_by',
        'imported_at',
    ];

    protected function casts(): array
    {
        return ['imported_at' => 'datetime'];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function errors(): HasMany
    {
        return $this->hasMany(StockImportError::class);
    }
}
