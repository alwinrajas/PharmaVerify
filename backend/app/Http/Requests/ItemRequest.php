<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ItemRequest extends FormRequest
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
        $itemId = $this->route('item')?->id;

        return [
            'product_code' => ['required', 'string', 'max:60', Rule::unique('items', 'product_code')->ignore($itemId)],
            'barcode' => ['nullable', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:300'],
            'generic_name' => ['nullable', 'string', 'max:200'],
            'manufacturer' => ['nullable', 'string', 'max:200'],
            'uom' => ['required', 'string', 'max:20'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_code.unique' => 'This product code already exists in the item master.',
        ];
    }
}
