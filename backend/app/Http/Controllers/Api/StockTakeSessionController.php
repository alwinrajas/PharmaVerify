<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesIndexQueries;
use App\Http\Controllers\Controller;
use App\Http\Resources\StockTakeSessionResource;
use App\Models\StockTakeSession;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stock-take cycles.
 *
 * A cycle groups the lines of one sweep of one shop under a reference the
 * operator can quote. References are normally issued by the handheld and
 * recorded on import; this endpoint covers the other case, where a sweep is
 * raised in the web application and PharmaVerify numbers it the same way.
 *
 * Withdrawing a cycle is a soft delete: the row survives so its number is never
 * reissued, and the lines it holds stay readable.
 */
class StockTakeSessionController extends Controller
{
    use HandlesIndexQueries;

    public function index(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::STOCKTAKE_VIEW) || abort(403);

        $query = StockTakeSession::query()
            ->with(['shop', 'createdBy'])
            ->withCount('lines')
            ->visibleTo($request->user());

        $this->applySearch($query, $request, ['take_ref', 'counted_by_name']);
        $this->applyEquals($query, $request, [
            'shop_id' => 'shop_id',
            'status' => 'status',
            'source' => 'source',
        ]);
        $this->applyDateRange($query, $request, 'take_date', 'date_from', 'date_to');
        $this->applySort($query, $request, ['take_date', 'take_ref', 'take_number', 'status', 'id'], 'take_date');

        $paginator = $this->paginate($query, $request);

        return ApiResponse::success(
            StockTakeSessionResource::collection($paginator->items()),
            null,
            $this->paginationMeta($paginator)
        );
    }

    /**
     * Opens the next cycle for a shop.
     *
     * The number is the highest this shop has ever issued plus one, counting
     * withdrawn cycles, so a reference is never handed out twice.
     */
    public function store(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::STOCKTAKE_CREATE) || abort(403);

        $validated = $request->validate([
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
            'take_date' => ['nullable', 'date'],
        ]);

        $this->assertShopVisible($request, (int) $validated['shop_id']);

        $session = StockTakeSession::openFor(
            (int) $validated['shop_id'],
            $request->user(),
            $validated['take_date'] ?? null
        );

        activity('stock_take')
            ->causedBy($request->user())
            ->performedOn($session)
            ->withProperties(['take_ref' => $session->take_ref, 'shop_id' => $session->shop_id])
            ->log('Stock take cycle opened');

        return ApiResponse::success(
            new StockTakeSessionResource($session->load(['shop', 'createdBy'])),
            sprintf('Stock take %s opened.', $session->take_ref),
            [],
            201
        );
    }

    public function show(Request $request, StockTakeSession $stockTakeSession): JsonResponse
    {
        $request->user()->can(Permissions::STOCKTAKE_VIEW) || abort(403);
        $this->assertShopVisible($request, $stockTakeSession->shop_id);

        return ApiResponse::success(
            new StockTakeSessionResource($stockTakeSession->load(['shop', 'createdBy'])->loadCount('lines'))
        );
    }

    /** Marks a cycle finished. Its lines are untouched. */
    public function complete(Request $request, StockTakeSession $stockTakeSession): JsonResponse
    {
        $request->user()->can(Permissions::STOCKTAKE_CREATE) || abort(403);
        $this->assertShopVisible($request, $stockTakeSession->shop_id);

        $stockTakeSession->update([
            'status' => StockTakeSession::STATUS_COMPLETED,
            'item_count' => $stockTakeSession->lines()->count(),
            'completed_at' => now(),
        ]);

        return ApiResponse::success(
            new StockTakeSessionResource($stockTakeSession->fresh(['shop', 'createdBy'])),
            sprintf('Stock take %s completed.', $stockTakeSession->take_ref)
        );
    }

    private function assertShopVisible(Request $request, int $shopId): void
    {
        $user = $request->user();

        if ($user->hasRole('Shop User') && ! $user->shops->pluck('id')->contains($shopId)) {
            abort(403, 'You do not have access to that shop.');
        }
    }
}
