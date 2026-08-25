<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockImportResource extends JsonResource
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
            'file_name' => $this->file_name,
            'total_records' => $this->total_records,
            'success_records' => $this->success_records,
            'failed_records' => $this->failed_records,
            'replaced_records' => $this->replaced_records,
            'status' => $this->status,
            'failure_reason' => $this->failure_reason,
            'imported_by' => $this->whenLoaded('importedBy', fn () => $this->importedBy?->name),
            'imported_at' => $this->imported_at?->toIso8601String(),
            'errors' => StockImportErrorResource::collection($this->whenLoaded('errors')),
        ];
    }
}
