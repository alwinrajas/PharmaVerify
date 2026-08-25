<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesIndexQueries;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLineResource;
use App\Models\AuditLine;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Variance is derived, not stored separately: it is the difference between the
 * physical quantity counted on the shelf and the system quantity held for the
 * shop, carried on the audit line it belongs to.
 *
 *     Variance = Physical Quantity - System Quantity
 */
class VarianceController extends Controller
{
    use HandlesIndexQueries;

    public function index(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::VARIANCE_VIEW) || abort(403);

        $query = $this->baseQuery($request)->with(['audit.device', 'audit.shop', 'shop']);

        $this->applySort(
            $query,
            $request,
            ['product_code', 'description', 'system_qty', 'physical_qty', 'variance_qty', 'batch', 'expiry_date', 'id'],
            'variance_qty',
            'asc'
        );

        $paginator = $this->paginate($query, $request);

        return ApiResponse::success(
            AuditLineResource::collection($paginator->items()),
            null,
            $this->paginationMeta($paginator) + ['summary' => $this->summary($request)]
        );
    }

    /** Headline figures shown above the variance table. */
    public function summary(Request $request): array
    {
        $base = $this->baseQuery($request, ignoreDirection: true);

        $positive = (clone $base)->where('variance_qty', '>', 0);
        $negative = (clone $base)->where('variance_qty', '<', 0);

        return [
            'total_lines' => (clone $base)->count(),
            'positive_count' => (clone $positive)->count(),
            'positive_quantity' => (float) (clone $positive)->sum('variance_qty'),
            'negative_count' => (clone $negative)->count(),
            'negative_quantity' => (float) (clone $negative)->sum('variance_qty'),
            'zero_count' => (clone $base)->where('variance_qty', '=', 0)->count(),
            'net_variance' => (float) (clone $base)->sum('variance_qty'),
            'pending_adjustment' => (clone $base)
                ->where('variance_qty', '!=', 0)
                ->where('adjustment_status', AuditLine::ADJUSTMENT_NOT_ADJUSTED)
                ->count(),
        ];
    }

    public function summaryResponse(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::VARIANCE_VIEW) || abort(403);

        return ApiResponse::success($this->summary($request));
    }

    private function baseQuery(Request $request, bool $ignoreDirection = false): Builder
    {
        $query = AuditLine::query()->visibleTo($request->user());

        $this->applySearch($query, $request, ['product_code', 'barcode', 'description', 'batch']);
        $this->applyEquals($query, $request, [
            'shop_id' => 'shop_id',
            'audit_id' => 'audit_id',
            'product_code' => 'product_code',
            'barcode' => 'barcode',
            'batch' => 'batch',
            'adjustment_status' => 'adjustment_status',
        ]);

        if ($auditNumber = $request->query('audit_number')) {
            $query->whereHas('audit', fn ($audit) => $audit->where('audit_number', $auditNumber));
        }

        if ($deviceId = $request->query('device_id')) {
            $query->whereHas('audit', fn ($audit) => $audit->where('device_id', $deviceId));
        }

        if ($request->query('date_from') || $request->query('date_to')) {
            $query->whereHas('audit', function ($audit) use ($request) {
                if ($from = $request->query('date_from')) {
                    $audit->whereDate('audit_date', '>=', $from);
                }

                if ($to = $request->query('date_to')) {
                    $audit->whereDate('audit_date', '<=', $to);
                }
            });
        }

        if (! $ignoreDirection) {
            // Default view shows lines that actually differ; the client can ask
            // for positive, negative, zero or all explicitly.
            $direction = $request->query('variance', 'non_zero');
            $query->varianceDirection($direction === 'all' ? null : $direction);
        }

        return $query;
    }
}
