<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
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
            'device_code' => $this->device_code,
            'description' => $this->description,
            'serial_number' => $this->serial_number,
            'status' => $this->status,
            'last_submission_at' => $this->last_submission_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
