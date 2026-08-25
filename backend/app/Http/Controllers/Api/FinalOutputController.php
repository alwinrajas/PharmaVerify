<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\Concerns\HandlesIndexQueries;
use App\Http\Controllers\Controller;
use App\Http\Resources\FinalOutputResource;
use App\Models\Audit;
use App\Models\FinalOutput;
use App\Services\FinalOutputService;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FinalOutputController extends Controller
{
    use HandlesIndexQueries;

    public function __construct(private readonly FinalOutputService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::FINALOUTPUT_VIEW) || abort(403);

        $query = FinalOutput::query()
            ->with(['shop', 'audit.device', 'generatedBy'])
            ->visibleTo($request->user());

        $this->applySearch($query, $request, ['file_name']);
        $this->applyEquals($query, $request, [
            'shop_id' => 'shop_id',
            'audit_id' => 'audit_id',
            'onedrive_status' => 'onedrive_status',
        ]);
        $this->applyDateRange($query, $request, 'generated_at');
        $this->applySort($query, $request, ['generated_at', 'file_name', 'record_count', 'onedrive_status', 'id'], 'generated_at');

        $paginator = $this->paginate($query, $request);

        return ApiResponse::success(
            FinalOutputResource::collection($paginator->items()),
            null,
            $this->paginationMeta($paginator) + ['onedrive_driver' => config('onedrive.driver')]
        );
    }

    public function store(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::FINALOUTPUT_GENERATE) || abort(403);

        $validated = $request->validate([
            'audit_id' => ['required', 'integer', 'exists:audits,id'],
        ]);

        $audit = Audit::findOrFail($validated['audit_id']);
        $this->assertVisible($request, $audit->shop_id);

        $output = $this->service->generate($audit, $request->user());

        return ApiResponse::success(
            new FinalOutputResource($output),
            sprintf('Final output generated with %d record(s). It has not been uploaded yet.', $output->record_count),
            [],
            201
        );
    }

    public function show(Request $request, FinalOutput $finalOutput): JsonResponse
    {
        $request->user()->can(Permissions::FINALOUTPUT_VIEW) || abort(403);
        $this->assertVisible($request, $finalOutput->shop_id);

        return ApiResponse::success(
            new FinalOutputResource($finalOutput->load(['shop', 'audit.device', 'generatedBy']))
        );
    }

    /**
     * Uploads the file to OneDrive. Reached only from the explicit
     * Share to OneDrive action; nothing uploads on its own.
     */
    public function shareToOneDrive(Request $request, FinalOutput $finalOutput): JsonResponse
    {
        $request->user()->can(Permissions::ONEDRIVE_SHARE) || abort(403);
        $this->assertVisible($request, $finalOutput->shop_id);

        $output = $this->service->shareToOneDrive($finalOutput, $request->user());

        return ApiResponse::success(
            new FinalOutputResource($output),
            sprintf('%s was uploaded to OneDrive successfully.', $output->file_name)
        );
    }

    public function download(Request $request, FinalOutput $finalOutput)
    {
        $request->user()->can(Permissions::FINALOUTPUT_VIEW) || abort(403);
        $this->assertVisible($request, $finalOutput->shop_id);

        if (! $finalOutput->file_path || ! Storage::disk('local')->exists($finalOutput->file_path)) {
            throw new BusinessRuleException('The final output file is no longer available. Please generate it again.', 404);
        }

        return Storage::disk('local')->download($finalOutput->file_path, $finalOutput->file_name);
    }

    private function assertVisible(Request $request, int $shopId): void
    {
        if ($request->user()->isShopRestricted() && ! in_array($shopId, $request->user()->assignedShopIds(), true)) {
            abort(403, 'You do not have access to this shop.');
        }
    }
}
