<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesIndexQueries;
use App\Http\Controllers\Controller;
use App\Http\Resources\ItemStockResource;
use App\Models\ItemStock;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemStockController extends Controller
{
    use HandlesIndexQueries;

    public function index(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::STOCK_VIEW) || abort(403);

        $query = ItemStock::query()->with('shop')->visibleTo($request->user());

        $this->applySearch($query, $request, ['product_code', 'barcode', 'description', 'batch', 'shelf_location']);
        $this->applyEquals($query, $request, [
            'shop_id' => 'shop_id',
            'product_code' => 'product_code',
            'barcode' => 'barcode',
            'batch' => 'batch',
            'verification_status' => 'verification_status',
            'shelf_location' => 'shelf_location',
        ]);
        $this->applyDateRange($query, $request, 'expiry_date', 'expiry_from', 'expiry_to');

        if ($request->boolean('expiring_soon')) {
            $query->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<=', now()->addDays(90)->toDateString());
        }

        $this->applySort(
            $query,
            $request,
            ['product_code', 'barcode', 'description', 'system_qty', 'batch', 'expiry_date', 'shelf_location', 'verification_status', 'id'],
            'product_code',
            'asc'
        );

        $paginator = $this->paginate($query, $request);

        return ApiResponse::success(
            ItemStockResource::collection($paginator->items()),
            null,
            $this->paginationMeta($paginator) + ['summary' => $this->summary($request)]
        );
    }

    public function show(Request $request, ItemStock $itemStock): JsonResponse
    {
        $request->user()->can(Permissions::STOCK_VIEW) || abort(403);

        return ApiResponse::success(new ItemStockResource($itemStock->load('shop')));
    }

    /**
     * Totals for the current shop selection, shown above the stock table.
     *
     * @return array<string, mixed>
     */
    private function summary(Request $request): array
    {
        $query = ItemStock::query()->visibleTo($request->user());

        if ($shopId = $request->query('shop_id')) {
            $query->where('shop_id', $shopId);
        }

        return [
            'record_count' => (clone $query)->count(),
            'total_quantity' => (float) (clone $query)->sum('system_qty'),
            'expiring_soon' => (clone $query)
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<=', now()->addDays(90)->toDateString())
                ->count(),
        ];
    }
}
