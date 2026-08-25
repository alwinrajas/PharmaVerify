<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditResource;
use App\Http\Resources\HhtSubmissionResource;
use App\Http\Resources\StockAdjustmentResource;
use App\Models\Audit;
use App\Models\AuditLine;
use App\Models\HhtSubmission;
use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Shop;
use App\Models\StockAdjustment;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $shopIds = $user->isShopRestricted() ? $user->assignedShopIds() : null;

        $shops = Shop::query()->when($shopIds, fn ($q) => $q->whereIn('id', $shopIds));
        $stock = ItemStock::query()->visibleTo($user);
        $audits = Audit::query()->visibleTo($user);
        $lines = AuditLine::query()->visibleTo($user);
        $submissions = HhtSubmission::query()->visibleTo($user);

        return ApiResponse::success([
            'cards' => [
                'total_shops' => (clone $shops)->where('status', 'active')->count(),
                'total_items' => Item::where('status', 'active')->count(),
                'stock_records' => (clone $stock)->count(),
                'hht_submissions_today' => (clone $submissions)->whereDate('received_at', now()->toDateString())->count(),
                'pending_verification' => (clone $lines)->where('verification_status', AuditLine::VERIFICATION_PENDING)->count(),
                'variance_items' => (clone $lines)->where('variance_qty', '!=', 0)->count(),
                'pending_adjustments' => (clone $lines)
                    ->where('variance_qty', '!=', 0)
                    ->where('adjustment_status', AuditLine::ADJUSTMENT_NOT_ADJUSTED)
                    ->count(),
                'completed_audits' => (clone $audits)
                    ->whereIn('status', [Audit::STATUS_VERIFIED, Audit::STATUS_ADJUSTED, Audit::STATUS_CLOSED])
                    ->count(),
            ],

            'variance_breakdown' => [
                'positive' => (clone $lines)->where('variance_qty', '>', 0)->count(),
                'negative' => (clone $lines)->where('variance_qty', '<', 0)->count(),
                'zero' => (clone $lines)->where('variance_qty', '=', 0)->count(),
            ],

            'recent_submissions' => HhtSubmissionResource::collection(
                (clone $submissions)->with(['shop', 'device'])->latest('received_at')->limit(5)->get()
            ),

            'recent_audits' => AuditResource::collection(
                (clone $audits)->with(['shop', 'device'])->latest('submitted_at')->limit(5)->get()
            ),

            'recent_adjustments' => StockAdjustmentResource::collection(
                StockAdjustment::query()
                    ->visibleTo($user)
                    ->with(['shop', 'audit.device', 'adjustedBy'])
                    ->latest('adjusted_at')
                    ->limit(5)
                    ->get()
            ),
        ]);
    }
}
