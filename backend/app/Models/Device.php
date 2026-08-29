<?php

namespace App\Models;

use App\Models\Concerns\ScopesToUserShops;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A handheld terminal.
 *
 * A device authenticates as itself rather than as a person: it holds its own
 * API token, issued once when an administrator pairs it, so a count is
 * attributed to the terminal that took it and no operator's session has to stay
 * alive for the terminal to report in.
 */
class Device extends Model
{
    use HasApiTokens;
    use HasFactory;
    use ScopesToUserShops;

    /** The ability a paired handheld's token carries, and the only one. */
    public const ABILITY_SUBMIT = 'hht:submit';

    protected $fillable = [
        'shop_id',
        'device_code',
        'description',
        'serial_number',
        'status',
        'last_submission_at',
        'pairing_code_hash',
        'pairing_expires_at',
        'paired_at',
        'last_seen_at',
    ];

    protected $hidden = ['pairing_code_hash'];

    protected function casts(): array
    {
        return [
            'last_submission_at' => 'datetime',
            'pairing_expires_at' => 'datetime',
            'paired_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** Has this device been paired and not since had its token revoked? */
    public function isPaired(): bool
    {
        return $this->paired_at !== null && $this->tokens()->exists();
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
