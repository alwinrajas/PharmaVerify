<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $deviceId = $this->route('device')?->id;

        return [
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
            'device_code' => [
                'required', 'string', 'max:50',
                Rule::unique('devices', 'device_code')
                    ->where(fn ($query) => $query->where('shop_id', $this->input('shop_id')))
                    ->ignore($deviceId),
            ],
            'description' => ['nullable', 'string', 'max:200'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'device_code.unique' => 'This device code is already registered against the selected shop.',
        ];
    }
}
