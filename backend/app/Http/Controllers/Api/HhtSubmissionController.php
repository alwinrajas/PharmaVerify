<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesIndexQueries;
use App\Http\Controllers\Controller;
use App\Http\Requests\HhtSubmissionRequest;
use App\Http\Resources\HhtSubmissionResource;
use App\Models\Device;
use App\Models\HhtSubmission;
use App\Services\HhtSubmissionService;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HhtSubmissionController extends Controller
{
    use HandlesIndexQueries;

    /**
     * Marks a refusal the device can act on: the pairing is valid, but it
     * belongs to a different shop or terminal than the count does.
     */
    public const ERROR_SHOP_MISMATCH = 'shop_mismatch';

    public function __construct(private readonly HhtSubmissionService $service) {}

    /**
     * Receives a completed count from a handheld device.
     *
     * Repeating a submission after a dropped connection is safe: the same
     * submission is acknowledged and the audit already created is returned.
     */
    public function store(HhtSubmissionRequest $request): JsonResponse
    {
        $payload = $request->validated();

        if ($refusal = $this->refuseIfFilingForSomeoneElse($request, $payload)) {
            return $refusal;
        }

        // An authenticated terminal has just proved it is out there. Recorded
        // before the count is processed, and only past the guard above, so a
        // rejected impostor never marks a real device as seen.
        //
        // Submissions were previously invisible to "last seen": a terminal
        // counting all day looked stale unless somebody opened its Settings.
        $caller = $request->user('sanctum');
        if ($caller instanceof Device) {
            $caller->forceFill(['last_seen_at' => now()])->save();
        }

        $result = $this->service->receive($payload);

        $submission = $result['submission'];
        $audit = $result['audit'];

        if ($result['duplicate']) {
            return ApiResponse::success([
                'submission_id' => $submission->id,
                'audit_id' => $audit->id,
                'audit_number' => $audit->audit_number,
                'audit_ref' => $audit->reference(),
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
            // The reference PharmaVerify knows this count by. Shown on the
            // handheld so an operator can quote it without opening the web app.
            'audit_ref' => $audit->reference(),
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

    /**
     * Stops a handheld filing a count in someone else's name.
     *
     * The shop and device in the payload decide which books the count lands in,
     * and until now they were taken at their word: a terminal paired to one
     * shop could file against any other shop that happened to have a device of
     * the same code. Its token proved only that it was *a* device, never *which*
     * device.
     *
     * A device may therefore file for itself and nothing else. Its identity
     * comes from the token, which it cannot choose.
     *
     * Signed-in staff are not held to this. The web simulator and back-office
     * corrections legitimately post for whichever shop the operator has rights
     * to, and those rights are already checked by permission and shop scoping.
     */
    private function refuseIfFilingForSomeoneElse(Request $request, array $payload): ?JsonResponse
    {
        $device = $request->user('sanctum');

        if (! $device instanceof Device) {
            return null;
        }

        $ownShop = $device->shop?->shop_code ?? '';
        $claimedShop = (string) ($payload['shop_code'] ?? '');
        $claimedDevice = (string) ($payload['device_code'] ?? '');

        if (strcasecmp($claimedShop, $ownShop) === 0 && strcasecmp($claimedDevice, (string) $device->device_code) === 0) {
            return null;
        }

        // Carries a code as well as a sentence. A handheld cannot read English
        // prose, and without this it can only see "403", which it would
        // otherwise report to the operator as a revoked pairing — sending them
        // to re-pair a terminal whose pairing is perfectly good.
        //
        // The shop the device actually belongs to is named. That is not a leak:
        // the holder of this token can read it from /hht/devices/me anyway, and
        // withholding it leaves the operator unable to act.
        return ApiResponse::error(
            sprintf(
                'This terminal is paired to %s and cannot file a count for %s. Pair it with the correct shop first.',
                $ownShop !== '' ? $ownShop : 'another shop',
                $claimedShop !== '' ? $claimedShop : 'that shop',
            ),
            403,
            [
                'code' => self::ERROR_SHOP_MISMATCH,
                'paired_shop_code' => $ownShop,
                'paired_device_code' => (string) $device->device_code,
                'attempted_shop_code' => $claimedShop,
            ],
        );
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
