<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Services\DevicePairingService;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pairing handhelds, from both ends.
 *
 * `issue` and `revoke` are the administrator's side and sit behind the usual
 * device permissions. `pair` is the terminal's side and is necessarily
 * unauthenticated — a device that has never paired has nothing to authenticate
 * with — so it is rate limited and answers every failure identically.
 */
class DevicePairingController extends Controller
{
    public function __construct(private readonly DevicePairingService $pairing) {}

    /** Issues a short-lived code for an administrator to read to the terminal. */
    public function issue(Request $request, Device $device): JsonResponse
    {
        $request->user()->can(Permissions::DEVICES_EDIT) || abort(403);

        $code = $this->pairing->issueCode($device, $request->user());

        return ApiResponse::success(
            [
                // The only time this is ever readable.
                'pairing_code' => $code,
                'expires_at' => $device->fresh()->pairing_expires_at?->toIso8601String(),
                'expires_in_minutes' => DevicePairingService::CODE_TTL_MINUTES,
                'device_code' => $device->device_code,
                'shop_code' => $device->shop?->shop_code,
            ],
            sprintf(
                'Pairing code issued for %s. It can be used once and expires in %d minutes.',
                $device->device_code,
                DevicePairingService::CODE_TTL_MINUTES
            )
        );
    }

    /** Revokes a device's token. The terminal must be paired again to report in. */
    public function revoke(Request $request, Device $device): JsonResponse
    {
        $request->user()->can(Permissions::DEVICES_EDIT) || abort(403);

        $this->pairing->unpair($device, $request->user());

        return ApiResponse::success(
            new DeviceResource($device->fresh('shop')),
            sprintf('%s has been unpaired. It can no longer submit until it is paired again.', $device->device_code)
        );
    }

    /**
     * The terminal's side: a code in, its own long-lived token out.
     *
     * Unauthenticated by necessity and throttled because of it.
     */
    public function pair(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_code' => ['required', 'string', 'max:50'],
            'pairing_code' => ['required', 'string', 'max:32'],
            'serial_number' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->pairing->pair(
            trim($validated['device_code']),
            trim($validated['pairing_code']),
            $validated['serial_number'] ?? null
        );

        $device = $result['device'];

        return ApiResponse::success([
            'token' => $result['token'],
            'device' => [
                'device_id' => $device->id,
                'device_code' => $device->device_code,
                'shop_id' => $device->shop_id,
                'shop_code' => $device->shop?->shop_code,
                'shop_name' => $device->shop?->shop_name,
            ],
        ], sprintf('Paired with %s at %s.', $device->device_code, $device->shop?->shop_code ?? 'this shop'), [], 201);
    }

    /**
     * Who the terminal is, according to its token.
     *
     * Gives the handheld a cheap way to prove the server is reachable and the
     * token still good, without sending a count to find out.
     */
    public function me(Request $request): JsonResponse
    {
        // Resolved from the token rather than whatever the default guard
        // happens to hold. This endpoint answers to a paired terminal and to
        // nothing else — a signed-in person reaching it would otherwise be
        // told about a device that is not theirs.
        $device = $request->user('sanctum');

        abort_unless($device instanceof Device, 403, 'This endpoint is for paired handheld devices.');

        $device->forceFill(['last_seen_at' => now()])->save();

        return ApiResponse::success([
            'device_id' => $device->id,
            'device_code' => $device->device_code,
            'shop_id' => $device->shop_id,
            'shop_code' => $device->shop?->shop_code,
            'shop_name' => $device->shop?->shop_name,
            'paired_at' => $device->paired_at?->toIso8601String(),
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
