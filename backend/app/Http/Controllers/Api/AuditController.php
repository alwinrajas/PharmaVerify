<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesIndexQueries;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLineResource;
use App\Http\Resources\AuditResource;
use App\Models\Audit;
use App\Models\AuditLine;
use App\Services\VerificationService;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    use HandlesIndexQueries;

    public function __construct(private readonly VerificationService $verification) {}

    public function index(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::AUDITS_VIEW) || abort(403);

        $query = Audit::query()->with(['shop', 'device', 'verifiedBy'])->visibleTo($request->user());

        $this->applySearch($query, $request, ['audit_ref', 'hht_user']);
        $this->applyEquals($query, $request, [
            'shop_id' => 'shop_id',
            'device_id' => 'device_id',
            'audit_number' => 'audit_number',
            'status' => 'status',
        ]);
        $this->applyDateRange($query, $request, 'audit_date');
        $this->applySort($query, $request, ['audit_date', 'audit_number', 'submitted_at', 'item_count', 'variance_count', 'status', 'id'], 'submitted_at');

        $paginator = $this->paginate($query, $request);

        return ApiResponse::success(
            AuditResource::collection($paginator->items()),
            null,
            $this->paginationMeta($paginator)
        );
    }

    public function show(Request $request, Audit $audit): JsonResponse
    {
        $request->user()->can(Permissions::AUDITS_VIEW) || abort(403);
        $this->assertVisible($request, $audit);

        return ApiResponse::success(
            new AuditResource($audit->load(['shop', 'device', 'verifiedBy']))
        );
    }

    /**
     * The lines of one audit, paginated and filterable so that a large count
     * can be worked through comfortably on the verification screen.
     */
    public function lines(Request $request, Audit $audit): JsonResponse
    {
        $request->user()->can(Permissions::AUDITS_VIEW) || abort(403);
        $this->assertVisible($request, $audit);

        $query = $audit->lines()->with('verifiedBy')->getQuery();

        $this->applySearch($query, $request, ['product_code', 'barcode', 'description', 'batch']);
        $this->applyEquals($query, $request, [
            'verification_status' => 'verification_status',
            'adjustment_status' => 'adjustment_status',
            'batch' => 'batch',
        ]);

        if ($direction = $request->query('variance')) {
            $query->varianceDirection($direction);
        }

        $this->applySort(
            $query,
            $request,
            ['product_code', 'description', 'system_qty', 'physical_qty', 'variance_qty', 'batch', 'expiry_date', 'verification_status', 'id'],
            'product_code',
            'asc'
        );

        $paginator = $this->paginate($query, $request);

        return ApiResponse::success(
            AuditLineResource::collection($paginator->items()),
            null,
            $this->paginationMeta($paginator) + ['summary' => $this->lineSummary($audit)]
        );
    }

    /** Marks every outstanding line of the audit as verified. */
    public function verify(Request $request, Audit $audit): JsonResponse
    {
        $request->user()->can(Permissions::VERIFICATION_EDIT) || abort(403);
        $this->assertVisible($request, $audit);

        $audit = $this->verification->verifyAudit($audit, $request->user());

        return ApiResponse::success(new AuditResource($audit), 'Audit verified successfully.');
    }

    private function assertVisible(Request $request, Audit $audit): void
    {
        if ($request->user()->isShopRestricted() && ! in_array($audit->shop_id, $request->user()->assignedShopIds(), true)) {
            abort(403, 'You do not have access to this audit.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function lineSummary(Audit $audit): array
    {
        $lines = $audit->lines();

        return [
            'total_lines' => (clone $lines)->count(),
            'positive_variance' => (clone $lines)->where('variance_qty', '>', 0)->count(),
            'negative_variance' => (clone $lines)->where('variance_qty', '<', 0)->count(),
            'zero_variance' => (clone $lines)->where('variance_qty', '=', 0)->count(),
            'pending_verification' => (clone $lines)->where('verification_status', AuditLine::VERIFICATION_PENDING)->count(),
            'adjusted' => (clone $lines)->where('adjustment_status', AuditLine::ADJUSTMENT_ADJUSTED)->count(),
            'unknown_items' => (clone $lines)->where('is_unknown_item', true)->count(),
            'net_variance' => (float) (clone $lines)->sum('variance_qty'),
        ];
    }
}
