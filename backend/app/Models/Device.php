<?php

namespace App\Models;

use App\Models\Concerns\ScopesToUserShops;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    use HasFactory;
    use ScopesToUserShops;

    protected $fillable = [
        'shop_id',
        'device_code',
        'description',
        'serial_number',
        'status',
        'last_submission_at',
    ];

    protected function casts(): array
    {
        return ['last_submission_at' => 'datetime'];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(Audit::class);
    }
}
