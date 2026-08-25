<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesIndexQueries;
use App\Http\Controllers\Controller;
use App\Http\Requests\ItemRequest;
use App\Http\Resources\ItemResource;
use App\Models\Item;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    use HandlesIndexQueries;

    public function index(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::ITEMS_VIEW) || abort(403);

        $query = Item::query();

        $this->applySearch($query, $request, ['product_code', 'barcode', 'description', 'generic_name', 'manufacturer']);
        $this->applyEquals($query, $request, ['status' => 'status', 'uom' => 'uom', 'manufacturer' => 'manufacturer']);
        $this->applySort($query, $request, ['product_code', 'barcode', 'description', 'uom', 'price', 'status', 'created_at', 'id'], 'product_code', 'asc');

        $paginator = $this->paginate($query, $request);

        return ApiResponse::success(
            ItemResource::collection($paginator->items()),
            null,
            $this->paginationMeta($paginator)
        );
    }

    public function store(ItemRequest $request): JsonResponse
    {
        $request->user()->can(Permissions::ITEMS_CREATE) || abort(403);

        $item = Item::create($request->validated() + [
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return ApiResponse::success(new ItemResource($item), 'Item created successfully.', [], 201);
    }

    public function show(Request $request, Item $item): JsonResponse
    {
        $request->user()->can(Permissions::ITEMS_VIEW) || abort(403);

        return ApiResponse::success(new ItemResource($item));
    }

    public function update(ItemRequest $request, Item $item): JsonResponse
    {
        $request->user()->can(Permissions::ITEMS_EDIT) || abort(403);

        $item->update($request->validated() + ['updated_by' => $request->user()->id]);

        return ApiResponse::success(new ItemResource($item->fresh()), 'Item updated successfully.');
    }

    public function destroy(Request $request, Item $item): JsonResponse
    {
        $request->user()->can(Permissions::ITEMS_DELETE) || abort(403);

        $item->delete();

        return ApiResponse::success(null, 'Item removed successfully.');
    }
}
