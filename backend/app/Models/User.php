<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'employee_code',
        'phone',
        'status',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class, 'shop_user')->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Administrators and supervisors are not restricted to assigned shops.
     * Anyone else only ever sees the shops explicitly assigned to them.
     */
    public function isShopRestricted(): bool
    {
        return ! $this->can('shops.view_all');
    }

    /**
     * @return array<int, int>
     */
    public function assignedShopIds(): array
    {
        return $this->shops()->pluck('shops.id')->all();
    }
}
