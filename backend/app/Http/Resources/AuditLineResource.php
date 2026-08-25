<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'audit_id' => $this->audit_id,
            'audit_number' => $this->whenLoaded('audit', fn () => $this->audit->audit_number),
            'shop_id' => $this->shop_id,
            'shop_code' => $this->whenLoaded('shop', fn () => $this->shop->shop_code),
            'device_code' => $this->whenLoaded('audit', fn () => $this->audit->device?->device_code),
            'item_stock_id' => $this->item_stock_id,
            'product_code' => $this->product_code,
            'barcode' => $this->barcode,
            'description' => $this->description,
            'system_qty' => (float) $this->system_qty,
            'physical_qty' => (float) $this->physical_qty,
            'variance_qty' => (float) $this->variance_qty,
            'uom' => $this->uom,
            'price' => (float) $this->price,
            'batch' => $this->batch,
            'expiry_date' => $this->expiry_date?->toDateString(),
            'shelf_location' => $this->shelf_location,
            'is_unknown_item' => (bool) $this->is_unknown_item,
            'verification_status' => $this->verification_status,
            'adjustment_status' => $this->adjustment_status,
            'verified_by' => $this->whenLoaded('verifiedBy', fn () => $this->verifiedBy?->name),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'adjusted_at' => $this->adjusted_at?->toIso8601String(),
            'remarks' => $this->remarks,
        ];
    }
}
