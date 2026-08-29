<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesIndexQueries;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLineResource;
use App\Models\AuditLine;
use App\Services\VerificationService;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerificationController extends Controller
{
    use HandlesIndexQueries;

    public function __construct(private readonly VerificationService $service) {}

    /**
     * The verification worklist: every counted line across the audits the user
     * is allowed to see, with the system and physical quantities side by side.
     */
    public function index(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::AUDITS_VIEW) || abort(403);

        $query = AuditLine::query()
            ->with(['audit.device', 'audit.shop', 'shop', 'verifiedBy'])
            ->visibleTo($request->user());

        $this->applySearch($query, $request, ['product_code', 'barcode', 'description', 'batch']);
        $this->applyEquals($query, $request, [
            'shop_id' => 'shop_id',
            'audit_id' => 'audit_id',
            'verification_status' => 'verification_status',
            'adjustment_status' => 'adjustment_status',
            'batch' => 'batch',
        ]);

        if ($auditNumber = $request->query('audit_number')) {
            $query->whereHas('audit', fn ($audit) => $audit->where('audit_number', $auditNumber));
        }

        if ($deviceId = $request->query('device_id')) {
            $query->whereHas('audit', fn ($audit) => $audit->where('device_id', $deviceId));
        }

        if ($direction = $request->query('variance')) {
            $query->varianceDirection($direction);
        }

        $this->applySort(
            $query,
            $request,
            ['product_code', 'description', 'system_qty', 'physical_qty', 'loose_qty', 'variance_qty', 'batch', 'verification_status', 'id'],
            'id',
            'asc'
        );

        $paginator = $this->paginate($query, $request);

        return ApiResponse::success(
            AuditLineResource::collection($paginator->items()),
            null,
            $this->paginationMeta($paginator)
        );
    }

    /**
     * Corrects a line on a completed audit. Permission gated and fully logged.
     */
    public function update(Request $request, AuditLine $auditLine): JsonResponse
    {
        $request->user()->can(Permissions::VERIFICATION_EDIT) || abort(403);
        $this->assertVisible($request, $auditLine);

        $validated = $request->validate([
            'physical_qty' => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
            'loose_qty' => ['sometimes', 'numeric', 'min:0', 'max:99999999'],
            'batch' => ['sometimes', 'nullable', 'string', 'max:60'],
            'expiry_date' => ['sometimes', 'nullable', 'date'],
            'shelf_location' => ['sometimes', 'nullable', 'string', 'max:100'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:500'],
            'mark_verified' => ['sometimes', 'boolean'],
        ], [
            'physical_qty.min' => 'A physical quantity cannot be negative.',
            'loose_qty.min' => 'A loose quantity cannot be negative.',
        ]);

        $markVerified = (bool) ($validated['mark_verified'] ?? true);
        unset($validated['mark_verified']);

        if (array_key_exists('batch', $validated) && $validated['batch'] === null) {
            $validated['batch'] = '';
        }

        $line = $this->service->updateLine($auditLine, $validated, $request->user(), $markVerified);

        return ApiResponse::success(
            new AuditLineResource($line->load(['audit.device', 'shop', 'verifiedBy'])),
            'Verification saved successfully.'
        );
    }

    private function assertVisible(Request $request, AuditLine $line): void
    {
        if ($request->user()->isShopRestricted() && ! in_array($line->shop_id, $request->user()->assignedShopIds(), true)) {
            abort(403, 'You do not have access to this audit line.');
        }
    }
}
