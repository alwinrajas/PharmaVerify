<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HhtSubmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'submission_uid' => $this->submission_uid,
            'shop_id' => $this->shop_id,
            'shop_code' => $this->whenLoaded('shop', fn () => $this->shop->shop_code),
            'shop_name' => $this->whenLoaded('shop', fn () => $this->shop->shop_name),
            'device_id' => $this->device_id,
            'device_code' => $this->whenLoaded('device', fn () => $this->device->device_code),
            'audit_number' => $this->audit_number,
            'audit_date' => $this->audit_date?->toDateString(),
            'hht_user' => $this->hht_user,
            'app_version' => $this->app_version,
            'item_count' => $this->item_count,
            'status' => $this->status,
            'message' => $this->message,
            'audit_id' => $this->audit_id,
            'received_at' => $this->received_at?->toIso8601String(),
            'audit' => new AuditResource($this->whenLoaded('audit')),
        ];
    }
}
