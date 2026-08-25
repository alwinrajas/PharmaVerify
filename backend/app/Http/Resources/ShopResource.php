<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShopResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shop_code' => $this->shop_code,
            'shop_name' => $this->shop_name,
            'address' => $this->address,
            'city' => $this->city,
            'contact_person' => $this->contact_person,
            'contact_number' => $this->contact_number,
            'status' => $this->status,
            'devices_count' => $this->whenCounted('devices'),
            'item_stocks_count' => $this->whenCounted('itemStocks'),
            'audits_count' => $this->whenCounted('audits'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
