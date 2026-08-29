<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockTakeResource extends JsonResource
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
            'stock_take_session_id' => $this->stock_take_session_id,
            // Null for a take recorded ad hoc, which is what every take
            // predating stock-take sessions is.
            'take_ref' => $this->whenLoaded('session', fn () => $this->session?->take_ref),
            'audit_id' => $this->audit_id,
            'audit_number' => $this->whenLoaded('audit', fn () => $this->audit?->audit_number),
            'barcode' => $this->barcode,
            'product_code' => $this->product_code,
            'description' => $this->description,
            'physical_qty' => (float) $this->physical_qty,
            // Counted outside a full pack, alongside the whole units.
            'loose_qty' => (float) $this->loose_qty,
            'uom' => $this->uom,
            'batch' => $this->batch,
            'expiry_date' => $this->expiry_date?->toDateString(),
            'shelf_location' => $this->shelf_location,
            'status' => $this->status,
            'remarks' => $this->remarks,
            'taken_by' => $this->whenLoaded('takenBy', fn () => $this->takenBy?->name),
            'taken_at' => $this->taken_at?->toIso8601String(),
        ];
    }
}
