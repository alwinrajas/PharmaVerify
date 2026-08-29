<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockTakeSessionResource extends JsonResource
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
            // STK-ddMMyyyy-NNNN, as issued by the handheld or by this side.
            'take_ref' => $this->take_ref,
            'take_number' => $this->take_number,
            'take_date' => $this->take_date?->toDateString(),
            'status' => $this->status,
            'source' => $this->source,
            'item_count' => $this->when(
                $this->lines_count !== null,
                fn () => (int) $this->lines_count,
                fn () => (int) $this->item_count
            ),
            'counted_by_name' => $this->counted_by_name,
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
