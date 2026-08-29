<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The contract an HHT device posts when the operator taps Share / Submit.
 *
 * A shop and device may be identified either by their internal id or by the
 * code printed on the device, so that the handheld does not have to carry the
 * server's primary keys.
 */
class HhtSubmissionRequest extends FormRequest
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
        return [
            'submission_uid' => ['nullable', 'string', 'max:80'],
            'shop_id' => ['required_without:shop_code', 'nullable', 'integer'],
            'shop_code' => ['required_without:shop_id', 'nullable', 'string', 'max:50'],
            'device_id' => ['required_without:device_code', 'nullable', 'integer'],
            'device_code' => ['required_without:device_id', 'nullable', 'string', 'max:50'],
            'audit_number' => ['required', 'integer', 'min:1', 'max:999999'],
            'audit_date' => ['required', 'date'],
            'hht_user' => ['nullable', 'string', 'max:150'],
            'app_version' => ['nullable', 'string', 'max:30'],

            'items' => ['required', 'array', 'min:1'],
            // The identifier the handheld scans. `barcode` stays accepted so
            // devices that predate the GTIN mapping keep working.
            'items.*.gtin' => ['nullable', 'string', 'max:20'],
            'items.*.barcode' => ['nullable', 'string', 'max:60'],
            'items.*.product_code' => ['required_without_all:items.*.barcode,items.*.gtin', 'nullable', 'string', 'max:60'],
            'items.*.description' => ['nullable', 'string', 'max:300'],
            'items.*.physical_quantity' => ['required', 'numeric', 'min:0', 'max:99999999'],
            // Counted alongside the whole units, not carved out of them, so
            // there is deliberately no rule tying it to the physical figure.
            'items.*.loose_quantity' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'items.*.batch' => ['nullable', 'string', 'max:60'],
            'items.*.expiry' => ['nullable', 'date'],
            'items.*.uom' => ['nullable', 'string', 'max:20'],
            'items.*.shelf_location' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'The submission does not contain any counted items.',
            'items.*.physical_quantity.min' => 'A physical quantity cannot be negative.',
            'items.*.physical_quantity.required' => 'Every counted item must carry a physical quantity.',
        ];
    }
}
