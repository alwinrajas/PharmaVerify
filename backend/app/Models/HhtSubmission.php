<?php

namespace App\Models;

use App\Models\Concerns\ScopesToUserShops;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HhtSubmission extends Model
{
    use ScopesToUserShops;

    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DUPLICATE = 'duplicate_ignored';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'submission_uid',
        'shop_id',
        'device_id',
        'audit_number',
        'audit_date',
        'hht_user',
        'app_version',
        'item_count',
        'payload_hash',
        'status',
        'message',
        'audit_id',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'audit_date' => 'date',
            'received_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }
}
