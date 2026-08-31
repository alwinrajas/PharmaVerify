<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLineResource;
use App\Http\Resources\AuditResource;
use App\Models\Audit;
use App\Models\AuditLine;
use App\Models\ItemStock;
use App\Models\Shop;
use App\Services\WebAuditService;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Counting a shelf from the browser.
 *
 * Separate from HhtSubmissionController because the two are different shapes of
 * the same act: a handheld posts one finished count, while this accumulates one
 * over several requests as the operator works down a shelf. They meet again
 * immediately afterwards — both produce ordinary Audit and AuditLine rows, and
 * everything downstream treats them alike.
 */
class WebAuditController extends Controller
{
    public function __construct(private readonly WebAuditService $service) {}

    /**
     * Opens a count for a shop, or hands back the one already open.
     *
     * Counting is a change to the record, so it is gated on the permission
     * that governs editing a count rather than merely viewing one.
     */
    public function start(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::VERIFICATION_EDIT) || abort(403);

        $validated = $request->validate([
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
        ]);

        $shop = Shop::findOrFail($validated['shop_id']);

        // Shop scoping is the application's own rule, not a filter the client
        // may choose: a user restricted to one branch must not be able to open
        // a count in another by naming its id.
        $this->assertShopIsVisible($request, $shop);

        $audit = $this->service->start($shop, $request->user());

        return ApiResponse::success(
            new AuditResource($audit->load(['shop', 'device'])),
            sprintf('Audit %s is open for counting.', $audit->reference()),
            [],
            201
        );
    }

    /**
     * What the shop holds under a scanned code.
     *
     * Returns every matching batch. Choosing between them is the operator's
     * job, since only they can see which box is in their hand.
     */
    public function lookup(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::VERIFICATION_EDIT) || abort(403);

        $validated = $request->validate([
            'shop_id' => ['required', 'integer', 'exists:shops,id'],
            'code' => ['required', 'string', 'max:80'],
        ]);

        $shop = Shop::findOrFail($validated['shop_id']);
        $this->assertShopIsVisible($request, $shop);

        $matches = $this->service->findStock($shop, $validated['code']);

        if ($matches->isEmpty()) {
            // Deliberately not a 404: the request was well formed and the
            // answer — this shop does not stock that code — is the useful
            // result, not an error. It also keeps the scanner loop from
            // treating a miss as a failure it should retry.
            return ApiResponse::success(
                ['matches' => [], 'found' => false],
                sprintf('%s was not found in %s. Check the barcode, or the product may not be stocked here.', $validated['code'], $shop->shop_code)
            );
        }

        return ApiResponse::success([
            'found' => true,
            'matches' => $matches->map(fn (ItemStock $stock) => [
                'item_stock_id' => $stock->id,
                'product_code' => $stock->product_code,
                'description' => $stock->description,
                'barcode' => $stock->barcode,
                'gtin' => $stock->gtin,
                'batch' => $stock->batch,
                'expiry_date' => $stock->expiry_date?->toDateString(),
                'system_qty' => (float) $stock->system_qty,
                'uom' => $stock->uom,
                'price' => (float) $stock->price,
                'shelf_location' => $stock->shelf_location,
            ])->all(),
        ]);
    }

    /** Records one counted product against an open audit. */
    public function count(Request $request, Audit $audit): JsonResponse
    {
        $request->user()->can(Permissions::VERIFICATION_EDIT) || abort(403);
        $this->assertShopIsVisible($request, $audit->shop);

        $validated = $request->validate([
            'item_stock_id' => ['required', 'integer', 'exists:item_stocks,id'],
            'physical_qty' => ['required', 'numeric', 'min:0'],
            'loose_qty' => ['nullable', 'numeric', 'min:0'],
        ]);

        $stock = ItemStock::findOrFail($validated['item_stock_id']);

        $line = $this->service->recordCount(
            $audit,
            $stock,
            (float) $validated['physical_qty'],
            (float) ($validated['loose_qty'] ?? 0),
            $request->user()
        );

        return ApiResponse::success(
            new AuditLineResource($line),
            sprintf('%s counted.', $line->product_code),
            ['audit' => new AuditResource($audit->fresh())],
            201
        );
    }

    /** Removes a counted line from an open audit. */
    public function removeCount(Request $request, Audit $audit, AuditLine $line): JsonResponse
    {
        $request->user()->can(Permissions::VERIFICATION_EDIT) || abort(403);
        $this->assertShopIsVisible($request, $audit->shop);

        $this->service->removeCount($audit, $line);

        return ApiResponse::success(
            ['audit' => new AuditResource($audit->fresh())],
            'The counted line was removed.'
        );
    }

    /** Closes the audit so its variances can be adjusted. */
    public function complete(Request $request, Audit $audit): JsonResponse
    {
        $request->user()->can(Permissions::VERIFICATION_EDIT) || abort(403);
        $this->assertShopIsVisible($request, $audit->shop);

        $completed = $this->service->complete($audit, $request->user());

        return ApiResponse::success(
            new AuditResource($completed->load(['shop', 'device'])),
            sprintf('Audit %s is complete and ready for adjustment.', $completed->reference())
        );
    }

    /**
     * Refuses a shop the signed-in user is not assigned to.
     *
     * Uses the same check AuditController already applies to a single audit.
     * The visibleTo scope that serves the list endpoints cannot be used here:
     * it filters a `shop_id` column, which rows about shops do not have — they
     * are the shop. Reusing it would have thrown rather than refused.
     */
    private function assertShopIsVisible(Request $request, Shop $shop): void
    {
        $user = $request->user();

        $allowed = ! $user->isShopRestricted()
            || in_array($shop->id, $user->assignedShopIds(), true);

        abort_unless($allowed, 403, 'You do not have access to that shop.');
    }
}
