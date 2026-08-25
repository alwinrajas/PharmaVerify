<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesIndexQueries;
use App\Http\Controllers\Controller;
use App\Http\Requests\HhtSubmissionRequest;
use App\Http\Resources\HhtSubmissionResource;
use App\Models\HhtSubmission;
use App\Services\HhtSubmissionService;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HhtSubmissionController extends Controller
{
    use HandlesIndexQueries;

    public function __construct(private readonly HhtSubmissionService $service) {}

    /**
     * Receives a completed count from a handheld device.
     *
     * Repeating a submission after a dropped connection is safe: the same
     * submission is acknowledged and the audit already created is returned.
     */
    public function store(HhtSubmissionRequest $request): JsonResponse
    {
        $result = $this->service->receive($request->validated());

        $submission = $result['submission'];
        $audit = $result['audit'];

        if ($result['duplicate']) {
            return ApiResponse::success([
                'submission_id' => $submission->id,
                'audit_id' => $audit->id,
                'audit_number' => $audit->audit_number,
                'status' => HhtSubmission::STATUS_DUPLICATE,
                'item_count' => $submission->item_count,
            ], 'This submission has already been received. The existing audit has been returned and no duplicate was created.');
        }

        activity('hht')
            ->performedOn($audit)
            ->withProperties([
                'shop' => $audit->shop?->shop_code,
                'device' => $audit->device?->device_code,
                'audit_number' => $audit->audit_number,
                'items' => $audit->item_count,
            ])
            ->log('HHT submission received');

        return ApiResponse::success([
            'submission_id' => $submission->id,
            'audit_id' => $audit->id,
            'audit_number' => $audit->audit_number,
            'status' => $submission->status,
            'item_count' => $submission->item_count,
        ], sprintf(
            'Submission accepted. Audit %d for %s / %s created with %d item(s).',
            $audit->audit_number,
            $audit->shop?->shop_code,
            $audit->device?->device_code,
            $submission->item_count
        ), [], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::HHT_VIEW) || abort(403);

        $query = HhtSubmission::query()->with(['shop', 'device'])->visibleTo($request->user());

        $this->applySearch($query, $request, ['submission_uid', 'hht_user']);
        $this->applyEquals($query, $request, [
            'shop_id' => 'shop_id',
            'device_id' => 'device_id',
            'audit_number' => 'audit_number',
            'status' => 'status',
        ]);
        $this->applyDateRange($query, $request, 'received_at');
        $this->applySort($query, $request, ['received_at', 'audit_number', 'item_count', 'status', 'id'], 'received_at');

        $paginator = $this->paginate($query, $request);

        return ApiResponse::success(
            HhtSubmissionResource::collection($paginator->items()),
            null,
            $this->paginationMeta($paginator)
        );
    }

    public function show(Request $request, HhtSubmission $hhtSubmission): JsonResponse
    {
        $request->user()->can(Permissions::HHT_VIEW) || abort(403);

        return ApiResponse::success(
            new HhtSubmissionResource($hhtSubmission->load(['shop', 'device', 'audit.lines', 'audit.shop', 'audit.device']))
        );
    }
}
