<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesIndexQueries;
use App\Http\Controllers\Controller;
use App\Http\Resources\StockAdjustmentResource;
use App\Models\AuditLine;
use App\Models\StockAdjustment;
use App\Services\StockAdjustmentService;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockAdjustmentController extends Controller
{
    use HandlesIndexQueries;

    public function __construct(private readonly StockAdjustmentService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::ADJUSTMENTS_VIEW) || abort(403);

        $query = StockAdjustment::query()
            ->with(['shop', 'audit.device', 'adjustedBy'])
            ->visibleTo($request->user());

        $this->applySearch($query, $request, ['product_code', 'barcode', 'description', 'batch', 'reason']);
        $this->applyEquals($query, $request, [
            'shop_id' => 'shop_id',
            'audit_id' => 'audit_id',
            'product_code' => 'product_code',
            'batch' => 'batch',
            'adjusted_by' => 'adjusted_by',
        ]);
        $this->applyDateRange($query, $request, 'adjusted_at');
        $this->applySort($query, $request, ['adjusted_at', 'product_code', 'variance_qty', 'new_system_qty', 'id'], 'adjusted_at');

        $paginator = $this->paginate($query, $request);

        return ApiResponse::success(
            StockAdjustmentResource::collection($paginator->items()),
            null,
            $this->paginationMeta($paginator)
        );
    }

    /**
     * Posts an adjustment. The change takes effect immediately; there is no
     * approval workflow in this business.
     */
    public function store(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::ADJUSTMENTS_CREATE) || abort(403);

        $validated = $request->validate([
            'audit_line_id' => ['required_without:audit_line_ids', 'nullable', 'integer', 'exists:audit_lines,id'],
            'audit_line_ids' => ['required_without:audit_line_id', 'nullable', 'array', 'min:1'],
            'audit_line_ids.*' => ['integer', 'exists:audit_lines,id'],
            'reason' => ['nullable', 'string', 'max:500'],
            // Confirms the caller has seen and accepted that system stock moved
            // after this audit line was counted; see StockAdjustmentService.
            'acknowledge_drift' => ['nullable', 'boolean'],
        ]);

        $reason = $validated['reason'] ?? null;
        $acknowledgeDrift = (bool) ($validated['acknowledge_drift'] ?? false);

        if (! empty($validated['audit_line_id'])) {
            $line = AuditLine::with('audit')->findOrFail($validated['audit_line_id']);
            $this->assertVisible($request, $line);

            $adjustment = $this->service->adjustLine($line, $request->user(), $reason, $acknowledgeDrift);

            return ApiResponse::success(
                new StockAdjustmentResource($adjustment),
                sprintf(
                    'Adjustment posted. System stock for %s is now %s.',
                    $adjustment->product_code,
                    rtrim(rtrim(number_format((float) $adjustment->new_system_qty, 3, '.', ''), '0'), '.')
                ),
                [],
                201
            );
        }

        $lineIds = $validated['audit_line_ids'];

        foreach (AuditLine::whereIn('id', $lineIds)->get() as $line) {
            $this->assertVisible($request, $line);
        }

        $result = $this->service->adjustLines($lineIds, $request->user(), $reason);
        $posted = $result['adjustments']->count();
        $skipped = $result['skipped'];

        $message = $skipped === []
            ? sprintf('%d adjustment(s) posted successfully.', $posted)
            : sprintf('%d adjustment(s) posted. %d could not be posted.', $posted, count($skipped));

        return ApiResponse::success([
            'adjustments' => StockAdjustmentResource::collection($result['adjustments']),
            'skipped' => $skipped,
        ], $message, [], 201);
    }

    public function show(Request $request, StockAdjustment $stockAdjustment): JsonResponse
    {
        $request->user()->can(Permissions::ADJUSTMENTS_VIEW) || abort(403);

        return ApiResponse::success(
            new StockAdjustmentResource($stockAdjustment->load(['shop', 'audit.device', 'adjustedBy']))
        );
    }

    private function assertVisible(Request $request, AuditLine $line): void
    {
        if ($request->user()->isShopRestricted() && ! in_array($line->shop_id, $request->user()->assignedShopIds(), true)) {
            abort(403, 'You do not have access to this audit line.');
        }
    }
}
