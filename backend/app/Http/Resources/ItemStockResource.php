<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemStockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shop_id' => $this->shop_id,
            'shop_code' => $this->whenLoaded('shop', fn () => $this->shop->shop_code),
            'shop_name' => $this->whenLoaded('shop', fn () => $this->shop->shop_name),
            'product_code' => $this->product_code,
            'barcode' => $this->barcode,
            'description' => $this->description,
            'system_qty' => (float) $this->system_qty,
            'uom' => $this->uom,
            'price' => (float) $this->price,
            'batch' => $this->batch,
            'expiry_date' => $this->expiry_date?->toDateString(),
            'shelf_location' => $this->shelf_location,
            'verification_status' => $this->verification_status,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
