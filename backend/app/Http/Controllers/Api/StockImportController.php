<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesIndexQueries;
use App\Http\Controllers\Controller;
use App\Http\Resources\StockImportErrorResource;
use App\Http\Resources\StockImportResource;
use App\Models\Shop;
use App\Models\StockImport;
use App\Services\StockImportService;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockImportController extends Controller
{
    use HandlesIndexQueries;

    public function __construct(private readonly StockImportService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::STOCK_VIEW) || abort(403);

        $query = StockImport::query()->with(['shop', 'importedBy'])->visibleTo($request->user());

        $this->applySearch($query, $request, ['file_name']);
        $this->applyEquals($query, $request, ['shop_id' => 'shop_id', 'status' => 'status']);
        $this->applyDateRange($query, $request, 'imported_at');
        $this->applySort($query, $request, ['imported_at', 'file_name', 'total_records', 'status', 'id'], 'imported_at');

        $paginator = $this->paginate($query, $request);

        return ApiResponse::success(
            StockImportResource::collection($paginator->items()),
            null,
            $this->paginationMeta($paginator)
        );
    }

    /**
     * Imports a stock file and replaces the shop's existing stock with it.
     */
    public function store(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::STOCK_IMPORT) || abort(403);

        $validated = $request->validate([
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
            'file' => ['required', 'file', 'mimes:xls,xlsx', 'max:20480'],
        ], [
            'file.mimes' => 'Only Excel files with an .xls or .xlsx extension can be imported.',
            'file.max' => 'The stock file must not be larger than 20 MB.',
        ]);

        $shop = Shop::findOrFail($validated['shop_id']);

        $result = $this->service->import($request->file('file'), $shop, $request->user());
        $import = $result['import'];

        $message = $import->failed_records > 0
            ? sprintf(
                'Stock import completed. %d record(s) imported, %d record(s) contain validation errors.',
                $import->success_records,
                $import->failed_records
            )
            : sprintf(
                'Stock import completed successfully. %d record(s) imported and %d previous record(s) replaced.',
                $import->success_records,
                $import->replaced_records
            );

        return ApiResponse::success(new StockImportResource($import), $message, [], 201);
    }

    public function show(Request $request, StockImport $stockImport): JsonResponse
    {
        $request->user()->can(Permissions::STOCK_VIEW) || abort(403);

        return ApiResponse::success(
            new StockImportResource($stockImport->load(['shop', 'importedBy']))
        );
    }

    public function errors(Request $request, StockImport $stockImport): JsonResponse
    {
        $request->user()->can(Permissions::STOCK_VIEW) || abort(403);

        $paginator = $stockImport->errors()->orderBy('row_number')->paginate(
            max(1, min((int) $request->query('per_page', 50), 200))
        );

        return ApiResponse::success(
            StockImportErrorResource::collection($paginator->items()),
            null,
            $this->paginationMeta($paginator)
        );
    }

    /** The column headings the importer understands, shown on the import screen. */
    public function template(): JsonResponse
    {
        return ApiResponse::success([
            'required' => ['Product Code', 'Product Description', 'System Stock'],
            'optional' => ['Shop', 'Barcode', 'UOM', 'Price', 'Batch', 'Expiry Date', 'Shelf Location'],
            'note' => 'Importing a file replaces all existing stock for the selected shop.',
        ]);
    }
}
