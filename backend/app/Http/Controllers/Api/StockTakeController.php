<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesIndexQueries;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLineResource;
use App\Http\Resources\StockTakeResource;
use App\Models\AuditLine;
use App\Models\StockTake;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stock found on the shelf that the shop's stock data does not carry.
 *
 * Recording one never creates an Item Master record; the entry is kept
 * separately so the business can decide what to do with it.
 */
class StockTakeController extends Controller
{
    use HandlesIndexQueries;

    public function index(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::STOCKTAKE_VIEW) || abort(403);

        $query = StockTake::query()->with(['shop', 'audit', 'takenBy'])->visibleTo($request->user());

        $this->applySearch($query, $request, ['barcode', 'product_code', 'description', 'batch', 'shelf_location']);
        $this->applyEquals($query, $request, [
            'shop_id' => 'shop_id',
            'audit_id' => 'audit_id',
            'status' => 'status',
            'barcode' => 'barcode',
        ]);
        $this->applyDateRange($query, $request, 'taken_at');
        $this->applySort($query, $request, ['taken_at', 'description', 'physical_qty', 'status', 'id'], 'taken_at');

        $paginator = $this->paginate($query, $request);

        return ApiResponse::success(
            StockTakeResource::collection($paginator->items()),
            null,
            $this->paginationMeta($paginator)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::STOCKTAKE_CREATE) || abort(403);

        $validated = $request->validate([
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
            'audit_id' => ['nullable', 'integer', 'exists:audits,id'],
            'audit_line_id' => ['nullable', 'integer', 'exists:audit_lines,id'],
            'barcode' => ['nullable', 'string', 'max:60'],
            'product_code' => ['nullable', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:300'],
            'physical_qty' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'uom' => ['nullable', 'string', 'max:20'],
            'batch' => ['nullable', 'string', 'max:60'],
            'expiry_date' => ['nullable', 'date'],
            'shelf_location' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ], [
            'physical_qty.min' => 'A physical quantity cannot be negative.',
            'description.required' => 'A product description is required so the item can be identified later.',
        ]);

        $this->assertShopVisible($request, (int) $validated['shop_id']);

        $stockTake = StockTake::create(array_merge($validated, [
            'batch' => $validated['batch'] ?? '',
            'uom' => $validated['uom'] ?? 'EA',
            'status' => 'recorded',
            'taken_by' => $request->user()->id,
            'taken_at' => now(),
        ]));

        // When the entry came from an audit line, note it on that line so it is
        // not mistaken for an outstanding variance later.
        if (! empty($validated['audit_line_id'])) {
            AuditLine::where('id', $validated['audit_line_id'])->update([
                'remarks' => 'Recorded as stock take',
                'updated_at' => now(),
            ]);
        }

        activity('stock_take')
            ->causedBy($request->user())
            ->performedOn($stockTake)
            ->withProperties([
                'shop_id' => $stockTake->shop_id,
                'barcode' => $stockTake->barcode,
                'description' => $stockTake->description,
                'physical_qty' => (float) $stockTake->physical_qty,
            ])
            ->log('Stock take recorded');

        return ApiResponse::success(
            new StockTakeResource($stockTake->load(['shop', 'takenBy'])),
            'Stock take recorded successfully. The item master has not been changed.',
            [],
            201
        );
    }

    public function update(Request $request, StockTake $stockTake): JsonResponse
    {
        $request->user()->can(Permissions::STOCKTAKE_CREATE) || abort(403);
        $this->assertShopVisible($request, $stockTake->shop_id);

        $validated = $request->validate([
            'description' => ['sometimes', 'string', 'max:300'],
            'physical_qty' => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
            'uom' => ['sometimes', 'nullable', 'string', 'max:20'],
            'batch' => ['sometimes', 'nullable', 'string', 'max:60'],
            'expiry_date' => ['sometimes', 'nullable', 'date'],
            'shelf_location' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', 'max:20'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $stockTake->update($validated);

        return ApiResponse::success(
            new StockTakeResource($stockTake->fresh(['shop', 'takenBy'])),
            'Stock take updated successfully.'
        );
    }

    public function destroy(Request $request, StockTake $stockTake): JsonResponse
    {
        $request->user()->can(Permissions::STOCKTAKE_CREATE) || abort(403);
        $this->assertShopVisible($request, $stockTake->shop_id);

        $stockTake->delete();

        return ApiResponse::success(null, 'Stock take removed successfully.');
    }

    /**
     * Counted products the shop stock does not carry, and which have not yet
     * been written up as a stock take. These are the rows a user works from.
     */
    public function candidates(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::STOCKTAKE_VIEW) || abort(403);

        $recorded = StockTake::query()
            ->visibleTo($request->user())
            ->whereNotNull('audit_line_id')
            ->pluck('audit_line_id');

        $query = AuditLine::query()
            ->with(['audit.device', 'shop'])
            ->visibleTo($request->user())
            ->where('is_unknown_item', true)
            ->whereNotIn('id', $recorded);

        $this->applyEquals($query, $request, ['shop_id' => 'shop_id', 'audit_id' => 'audit_id']);

        $paginator = $this->paginate($query->orderByDesc('id'), $request);

        return ApiResponse::success(
            AuditLineResource::collection($paginator->items()),
            null,
            $this->paginationMeta($paginator)
        );
    }

    private function assertShopVisible(Request $request, int $shopId): void
    {
        if ($request->user()->isShopRestricted() && ! in_array($shopId, $request->user()->assignedShopIds(), true)) {
            abort(403, 'You do not have access to this shop.');
        }
    }
}
