<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesIndexQueries;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShopRequest;
use App\Http\Resources\ShopResource;
use App\Models\Shop;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    use HandlesIndexQueries;

    public function index(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::SHOPS_VIEW) || abort(403);

        $query = Shop::query()->withCount(['devices', 'itemStocks', 'audits']);

        // A shop user only ever sees the shops assigned to them.
        if ($request->user()->isShopRestricted()) {
            $query->whereIn('id', $request->user()->assignedShopIds());
        }

        $this->applySearch($query, $request, ['shop_code', 'shop_name', 'city', 'contact_person']);
        $this->applyEquals($query, $request, ['status' => 'status']);
        $this->applySort($query, $request, ['shop_code', 'shop_name', 'city', 'status', 'created_at', 'id'], 'shop_code', 'asc');

        $paginator = $this->paginate($query, $request);

        return ApiResponse::success(
            ShopResource::collection($paginator->items()),
            null,
            $this->paginationMeta($paginator)
        );
    }

    public function store(ShopRequest $request): JsonResponse
    {
        $request->user()->can(Permissions::SHOPS_CREATE) || abort(403);

        $shop = Shop::create($request->validated() + [
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return ApiResponse::success(new ShopResource($shop), 'Shop created successfully.', [], 201);
    }

    public function show(Request $request, Shop $shop): JsonResponse
    {
        $request->user()->can(Permissions::SHOPS_VIEW) || abort(403);
        $this->assertVisible($request, $shop);

        return ApiResponse::success(
            new ShopResource($shop->loadCount(['devices', 'itemStocks', 'audits']))
        );
    }

    public function update(ShopRequest $request, Shop $shop): JsonResponse
    {
        $request->user()->can(Permissions::SHOPS_EDIT) || abort(403);

        $shop->update($request->validated() + ['updated_by' => $request->user()->id]);

        return ApiResponse::success(new ShopResource($shop->fresh()), 'Shop updated successfully.');
    }

    public function destroy(Request $request, Shop $shop): JsonResponse
    {
        $request->user()->can(Permissions::SHOPS_DELETE) || abort(403);

        $shop->delete();

        return ApiResponse::success(null, 'Shop removed successfully.');
    }

    /** Lightweight list used by every shop selector in the application. */
    public function options(Request $request): JsonResponse
    {
        $query = Shop::query()->where('status', 'active')->orderBy('shop_code');

        if ($request->user()->isShopRestricted()) {
            $query->whereIn('id', $request->user()->assignedShopIds());
        }

        return ApiResponse::success(
            $query->get(['id', 'shop_code', 'shop_name'])->map(fn (Shop $shop) => [
                'id' => $shop->id,
                'code' => $shop->shop_code,
                'label' => $shop->shop_code.' - '.$shop->shop_name,
            ])
        );
    }

    private function assertVisible(Request $request, Shop $shop): void
    {
        if ($request->user()->isShopRestricted() && ! in_array($shop->id, $request->user()->assignedShopIds(), true)) {
            abort(403, 'You do not have access to this shop.');
        }
    }

}
