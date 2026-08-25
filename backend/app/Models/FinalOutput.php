<?php

namespace App\Models;

use App\Models\Concerns\ScopesToUserShops;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinalOutput extends Model
{
    use ScopesToUserShops;

    public const ONEDRIVE_NOT_UPLOADED = 'not_uploaded';
    public const ONEDRIVE_UPLOADING = 'uploading';
    public const ONEDRIVE_UPLOADED = 'uploaded';
    public const ONEDRIVE_FAILED = 'failed';

    protected $fillable = [
        'shop_id',
        'audit_id',
        'file_name',
        'file_path',
        'record_count',
        'verification_status',
        'adjustment_status',
        'onedrive_status',
        'onedrive_item_id',
        'onedrive_url',
        'upload_attempts',
        'last_error',
        'uploaded_at',
        'generated_by',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'generated_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
