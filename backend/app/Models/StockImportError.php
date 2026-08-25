<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockImportError extends Model
{
    protected $fillable = [
        'stock_import_id',
        'row_number',
        'column_name',
        'column_value',
        'error_message',
    ];

    public function stockImport(): BelongsTo
    {
        return $this->belongsTo(StockImport::class);
    }
}
