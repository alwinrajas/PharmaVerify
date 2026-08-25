<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinalOutputResource extends JsonResource
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
            'audit_id' => $this->audit_id,
            'audit_number' => $this->whenLoaded('audit', fn () => $this->audit?->audit_number),
            'device_code' => $this->whenLoaded('audit', fn () => $this->audit?->device?->device_code),
            'file_name' => $this->file_name,
            'record_count' => $this->record_count,
            'verification_status' => $this->verification_status,
            'adjustment_status' => $this->adjustment_status,
            'onedrive_status' => $this->onedrive_status,
            'onedrive_url' => $this->onedrive_url,
            'upload_attempts' => $this->upload_attempts,
            'last_error' => $this->last_error,
            'uploaded_at' => $this->uploaded_at?->toIso8601String(),
            'generated_by' => $this->whenLoaded('generatedBy', fn () => $this->generatedBy?->name),
            'generated_at' => $this->generated_at?->toIso8601String(),
        ];
    }
}
