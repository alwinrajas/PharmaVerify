<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Only application-level settings are exposed here. Database credentials and
 * integration secrets stay in the environment file and are never returned.
 */
class SettingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $settings = AppSetting::orderBy('group_name')->orderBy('id')->get();

        return ApiResponse::success([
            'groups' => $settings->groupBy('group_name')->map(fn ($group) => $group->map(fn (AppSetting $setting) => [
                'id' => $setting->id,
                'key' => $setting->key_name,
                'label' => $setting->label,
                'description' => $setting->description,
                'value' => $setting->typedValue(),
                'type' => $setting->value_type,
                'is_editable' => $setting->is_editable,
            ])->values()),
            'integrations' => [
                'onedrive_driver' => config('onedrive.driver'),
                'onedrive_folder' => config('onedrive.folder'),
                'onedrive_configured' => app(\App\Services\OneDrive\OneDriveUploader::class)->isConfigured(),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::SETTINGS_MANAGE) || abort(403);

        $validated = $request->validate([
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.key' => ['required', 'string', 'exists:app_settings,key_name'],
            'settings.*.value' => ['nullable', 'string', 'max:1000'],
        ]);

        foreach ($validated['settings'] as $entry) {
            $setting = AppSetting::where('key_name', $entry['key'])->first();

            if (! $setting || ! $setting->is_editable) {
                continue;
            }

            $setting->update(['value' => $entry['value']]);
        }

        activity('settings')
            ->causedBy($request->user())
            ->withProperties(['keys' => array_column($validated['settings'], 'key')])
            ->log('Application settings updated');

        return ApiResponse::success(null, 'Settings saved successfully.');
    }
}
