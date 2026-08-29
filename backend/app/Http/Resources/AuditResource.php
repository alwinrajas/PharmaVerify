<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'audit_number' => $this->audit_number,
            // The handheld's own reference, derived for audits that predate the
            // format so a screen never has to choose between two shapes.
            'audit_ref' => $this->reference(),
            'source' => $this->source,
            'shop_id' => $this->shop_id,
            'shop_code' => $this->whenLoaded('shop', fn () => $this->shop->shop_code),
            'shop_name' => $this->whenLoaded('shop', fn () => $this->shop->shop_name),
            'device_id' => $this->device_id,
            'device_code' => $this->whenLoaded('device', fn () => $this->device->device_code),
            'hht_user' => $this->hht_user,
            'audit_date' => $this->audit_date?->toDateString(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'item_count' => $this->item_count,
            'variance_count' => $this->variance_count,
            'status' => $this->status,
            'verified_by' => $this->whenLoaded('verifiedBy', fn () => $this->verifiedBy?->name),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'lines' => AuditLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
