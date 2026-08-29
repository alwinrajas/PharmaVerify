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

        $this->applySearch($query, $request, ['product_code', 'barcode', 'gtin', 'description', 'batch', 'shelf_location']);
        $this->applyEquals($query, $request, [
            'shop_id' => 'shop_id',
            'product_code' => 'product_code',
            'barcode' => 'barcode',
            'gtin' => 'gtin',
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
     * Resolves a scanned code to the stock it identifies.
     *
     * The business has confirmed the GTIN is what the handheld scans, so the
     * code is looked up against `gtin` first. Only if that finds nothing are
     * the product code and the older 7-digit internal barcode tried, so
     * existing devices keep working without the 7-digit code ever displacing
     * the GTIN as the identifier.
     *
     * Every batch the code resolves to is returned rather than one being
     * chosen. Where a product is held in a single batch the caller has its
     * batch and expiry immediately; where it is held in several, the choice
     * belongs to the person counting, not to a rule invented here.
     */
    public function lookup(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::STOCK_VIEW) || abort(403);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:60'],
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
        ]);

        $code = trim($validated['code']);

        $base = fn () => ItemStock::query()
            ->with('shop')
            ->visibleTo($request->user())
            ->where('shop_id', $validated['shop_id']);

        $matches = $base()->where('gtin', $code)->orderBy('expiry_date')->get();
        $matchedOn = 'gtin';

        if ($matches->isEmpty()) {
            $matches = $base()->where('product_code', $code)->orderBy('expiry_date')->get();
            $matchedOn = 'product_code';
        }

        if ($matches->isEmpty()) {
            $matches = $base()->where('barcode', $code)->orderBy('expiry_date')->get();
            $matchedOn = 'barcode';
        }

        if ($matches->isEmpty()) {
            return ApiResponse::success(
                [],
                'No stock in this shop answers to that code.',
                ['code' => $code, 'matched_on' => null, 'match_count' => 0]
            );
        }

        return ApiResponse::success(
            ItemStockResource::collection($matches),
            null,
            [
                'code' => $code,
                'matched_on' => $matchedOn,
                'match_count' => $matches->count(),
                // More than one batch means batch and expiry cannot be filled
                // in automatically — the caller has to pick.
                'ambiguous' => $matches->count() > 1,
            ]
        );
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
