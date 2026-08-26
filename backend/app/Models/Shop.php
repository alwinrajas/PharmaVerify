<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Shop extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'shop_code',
        'ax_location_id',
        'shop_name',
        'address',
        'city',
        'contact_person',
        'contact_number',
        'status',
        'created_by',
        'updated_by',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['shop_code', 'ax_location_id', 'shop_name', 'address', 'city', 'contact_person', 'contact_number', 'status'])
            ->logOnlyDirty()
            ->useLogName('shop')
            ->dontSubmitEmptyLogs();
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function itemStocks(): HasMany
    {
        return $this->hasMany(ItemStock::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(Audit::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'shop_user')->withTimestamps();
    }
}
