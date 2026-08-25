<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockAdjustmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'audit_id' => $this->audit_id,
            'audit_line_id' => $this->audit_line_id,
            'audit_number' => $this->whenLoaded('audit', fn () => $this->audit?->audit_number),
            'device_code' => $this->whenLoaded('audit', fn () => $this->audit?->device?->device_code),
            'shop_id' => $this->shop_id,
            'shop_code' => $this->whenLoaded('shop', fn () => $this->shop->shop_code),
            'shop_name' => $this->whenLoaded('shop', fn () => $this->shop->shop_name),
            'product_code' => $this->product_code,
            'barcode' => $this->barcode,
            'description' => $this->description,
            'batch' => $this->batch,
            'old_system_qty' => (float) $this->old_system_qty,
            'physical_qty' => (float) $this->physical_qty,
            'variance_qty' => (float) $this->variance_qty,
            'new_system_qty' => (float) $this->new_system_qty,
            'reason' => $this->reason,
            'adjusted_by' => $this->whenLoaded('adjustedBy', fn () => $this->adjustedBy?->name),
            'adjusted_at' => $this->adjusted_at?->toIso8601String(),
        ];
    }
}
