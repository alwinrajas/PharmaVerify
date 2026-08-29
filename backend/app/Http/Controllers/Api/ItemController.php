<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesIndexQueries;
use App\Http\Controllers\Controller;
use App\Http\Requests\ItemRequest;
use App\Http\Resources\ItemResource;
use App\Models\Item;
use App\Services\StockReport\ItemImportService;
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

    /**
     * Loads the item master from the business export.
     *
     * This is the product list, on its own. It is run to seed the master and
     * again whenever products are added or their details change; it never
     * touches stock quantities, which arrive through Stock Import. Products
     * are created and updated, never removed.
     */
    public function import(Request $request, ItemImportService $importer): JsonResponse
    {
        $request->user()->can(Permissions::ITEMS_CREATE) || abort(403);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xls,xlsx', 'max:102400'],
            // Off, and the import only adds products it has never seen.
            'update_existing' => ['nullable', 'boolean'],
        ], [
            'file.mimes' => 'Only Excel files with an .xls or .xlsx extension can be imported.',
            'file.max' => 'The item file must not be larger than 100 MB.',
        ]);

        set_time_limit(0);

        $summary = $importer->import(
            $request->file('file'),
            $request->user(),
            (bool) ($validated['update_existing'] ?? true)
        );

        return ApiResponse::success(
            $summary,
            sprintf(
                'Item master imported. %s product(s) created and %s updated.',
                number_format($summary['created']),
                number_format($summary['updated'])
            ),
            [],
            201
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
