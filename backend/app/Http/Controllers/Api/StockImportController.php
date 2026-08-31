<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesIndexQueries;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\StockImportErrorResource;
use App\Http\Resources\StockImportResource;
use App\Models\Shop;
use App\Models\StockImport;
use App\Services\StockImportService;
use App\Services\StockReport\StockReportImportService;
use App\Services\StockReport\StockReportReader;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockImportController extends Controller
{
    use HandlesIndexQueries;

    public function __construct(
        private readonly StockImportService $service,
        private readonly StockReportReader $reportReader,
        private readonly StockReportImportService $reportImporter,
    ) {}

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
     * Imports a stock file and replaces the existing stock with it.
     *
     * Two shapes are accepted: the business Stock Report, which carries every
     * branch it was run for across three sheets, and the older flat single-sheet
     * file for one shop. Which one it is decides how it is processed.
     */
    public function store(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::STOCK_IMPORT) || abort(403);

        $validated = $request->validate([
            // A Stock Report names its own shops, so the picker is optional and
            // acts as a filter when it is used.
            'shop_id' => ['nullable', 'integer', 'exists:shops,id'],
            'file' => ['required', 'file', 'mimes:xls,xlsx', 'max:102400'],
        ], [
            'file.mimes' => 'Only Excel files with an .xls or .xlsx extension can be imported.',
            'file.max' => 'The stock file must not be larger than 100 MB.',
        ]);

        $file = $request->file('file');
        $shop = ! empty($validated['shop_id']) ? Shop::findOrFail($validated['shop_id']) : null;

        // A large report takes minutes of honest work rather than hanging.
        set_time_limit(0);

        if ($this->reportReader->looksLikeStockReport($file->getRealPath())) {
            return $this->storeStockReport($request, $file, $shop);
        }

        if (! $shop) {
            throw new BusinessRuleException(
                'Choose the shop this file belongs to. A shop is only optional for the business Stock Report, which names its own branches.'
            );
        }

        $result = $this->service->import($file, $shop, $request->user());
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

    /**
     * Judges a file without importing it.
     *
     * Importing replaces a shop's stock outright, so the user is shown which
     * branches the file covers, what each one currently holds and what would
     * take its place, before anything is asked of them. Nothing is written and
     * nothing is deleted by this call.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::STOCK_IMPORT) || abort(403);

        $validated = $request->validate([
            'shop_id' => ['nullable', 'integer', 'exists:shops,id'],
            'file' => ['required', 'file', 'mimes:xls,xlsx', 'max:102400'],
        ], [
            'file.mimes' => 'Only Excel files with an .xls or .xlsx extension can be imported.',
            'file.max' => 'The stock file must not be larger than 100 MB.',
        ]);

        $file = $request->file('file');
        $shop = ! empty($validated['shop_id']) ? Shop::findOrFail($validated['shop_id']) : null;

        set_time_limit(0);

        if (! $this->reportReader->looksLikeStockReport($file->getRealPath())) {
            throw new BusinessRuleException(
                'Only the business Stock Report can be checked before importing. The older single-sheet file imports directly.'
            );
        }

        return ApiResponse::success(
            $this->reportImporter->preview($file, $shop),
            null,
            ['format' => 'stock_report']
        );
    }

    /**
     * Handles the business Stock Report, which may cover several shops at once.
     */
    private function storeStockReport(Request $request, $file, ?Shop $shop): JsonResponse
    {
        $result = $this->reportImporter->import($file, $request->user(), $shop);
        $summary = $result['summary'];

        $message = $summary['failed'] > 0
            ? sprintf(
                'Stock Report imported. %s row(s) across %d shop(s) imported, %s row(s) contain validation errors.',
                number_format($summary['imported']),
                $summary['shops'],
                number_format($summary['failed'])
            )
            : sprintf(
                'Stock Report imported successfully. %s row(s) across %d shop(s) imported and %s previous record(s) replaced.',
                number_format($summary['imported']),
                $summary['shops'],
                number_format($summary['replaced'])
            );

        return ApiResponse::success(
            StockImportResource::collection($result['imports']),
            $message,
            ['summary' => $summary, 'format' => 'stock_report'],
            201
        );
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

    /** What the importer accepts, shown on the import screen. */
    public function template(): JsonResponse
    {
        return ApiResponse::success([
            // The business Stock Report is the expected file. The required
            // list is the confirmed mapping: a report missing one of these is
            // rejected before anything is written.
            'required' => [
                'stock', 'Item Master',
                'INVENTLOCATIONID', 'ITEMID', 'INVENTBATCHID', 'LOWERQTY', 'TOTALCOST',
                'ITEMNAME', 'SALESPRICE', 'FACTOR', 'GLOBALTRADEITEMNUMBER',
            ],
            'optional' => ['all batches', 'ITEMBARCODE', 'EXPDATE', 'HIGHERQTY', 'INVUNIT', 'COSTPERINVUNIT'],
            'note' => 'The business Stock Report is expected: a "stock" sheet and an "Item Master" sheet. An "all batches" sheet with ITEMBARCODE is used when present, and newer exports without it are accepted. '
                .'Importing replaces the stock of every shop the report covers. A shop is matched by its warehouse code '
                .'(INVENTLOCATIONID). GLOBALTRADEITEMNUMBER is the GTIN the handheld scans, SALESPRICE is the retail '
                .'selling price, and TOTALCOST is stored as the ERP supplies it. Where HIGHERQTY is absent, whole '
                .'quantity is worked out as LOWERQTY divided by FACTOR. A flat single-sheet file for one shop is still '
                .'accepted, with Product Code, Product Description and System Stock columns.',
        ]);
    }
}
