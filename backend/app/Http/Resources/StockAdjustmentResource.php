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

            // A readable identifier for the posting, derived rather than
            // stored: the ledger row's id is already unique and permanent, so
            // a second column carrying the same fact could only ever disagree
            // with it. Padded to sit tidily beside the audit references it
            // appears next to in the log.
            'adjustment_ref' => 'ADJ-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT),

            'audit_id' => $this->audit_id,
            // The audit's own reference - what an operator quotes and what the
            // handheld showed them. The numeric id means nothing outside the
            // database.
            'audit_ref' => $this->whenLoaded('audit', fn () => $this->audit?->reference()),
            // Where the count behind this adjustment came from: a handheld, a
            // spreadsheet, or someone counting in the browser. Investigating a
            // discrepancy starts with knowing which.
            'source' => $this->whenLoaded('audit', fn () => $this->audit?->source),
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

            // How far the stock actually moved, signed: negative took stock
            // off the books, positive put it on. Derived from the figures
            // either side so it can never disagree with them.
            //
            // Deliberately NOT the same as variance_qty. Variance is measured
            // as system minus counted, so a shortage is positive there and
            // negative here. Showing one under the other's label is the kind
            // of sign error that survives review.
            'adjustment_qty' => round((float) $this->new_system_qty - (float) $this->old_system_qty, 3),
            'reason' => $this->reason,
            'adjusted_by' => $this->whenLoaded('adjustedBy', fn () => $this->adjustedBy?->name),
            'adjusted_at' => $this->adjusted_at?->toIso8601String(),
        ];
    }
}
