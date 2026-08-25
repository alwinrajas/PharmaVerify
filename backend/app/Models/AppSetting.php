<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $fillable = [
        'group_name',
        'key_name',
        'value',
        'value_type',
        'label',
        'description',
        'is_editable',
    ];

    protected function casts(): array
    {
        return ['is_editable' => 'boolean'];
    }

    public function typedValue(): mixed
    {
        return match ($this->value_type) {
            'integer' => (int) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            default => $this->value,
        };
    }
}
