<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesIndexQueries;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    use HandlesIndexQueries;

    public function index(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::DEVICES_VIEW) || abort(403);

        $query = Device::query()->with('shop')->visibleTo($request->user());

        $this->applySearch($query, $request, ['device_code', 'description', 'serial_number']);
        $this->applyEquals($query, $request, ['status' => 'status', 'shop_id' => 'shop_id']);
        $this->applySort($query, $request, ['device_code', 'status', 'last_submission_at', 'created_at', 'id'], 'device_code', 'asc');

        $paginator = $this->paginate($query, $request);

        return ApiResponse::success(
            DeviceResource::collection($paginator->items()),
            null,
            $this->paginationMeta($paginator)
        );
    }

    public function store(DeviceRequest $request): JsonResponse
    {
        $request->user()->can(Permissions::DEVICES_CREATE) || abort(403);

        $device = Device::create($request->validated());

        return ApiResponse::success(new DeviceResource($device->load('shop')), 'Device registered successfully.', [], 201);
    }

    public function update(DeviceRequest $request, Device $device): JsonResponse
    {
        $request->user()->can(Permissions::DEVICES_EDIT) || abort(403);

        $device->update($request->validated());

        return ApiResponse::success(new DeviceResource($device->fresh('shop')), 'Device updated successfully.');
    }

    public function destroy(Request $request, Device $device): JsonResponse
    {
        $request->user()->can(Permissions::DEVICES_DELETE) || abort(403);

        $device->delete();

        return ApiResponse::success(null, 'Device removed successfully.');
    }

    /** Devices belonging to one shop, used by the HHT simulator and filters. */
    public function options(Request $request): JsonResponse
    {
        $query = Device::query()->where('status', 'active')->visibleTo($request->user())->orderBy('device_code');

        if ($shopId = $request->query('shop_id')) {
            $query->where('shop_id', $shopId);
        }

        return ApiResponse::success(
            $query->get(['id', 'shop_id', 'device_code'])->map(fn (Device $device) => [
                'id' => $device->id,
                'shop_id' => $device->shop_id,
                'code' => $device->device_code,
                'label' => $device->device_code,
            ])
        );
    }
}
