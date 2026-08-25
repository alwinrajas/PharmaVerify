<?php

namespace App\Services\OneDrive;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stands in for OneDrive until the client's Azure application registration is
 * available.
 *
 * It performs a real file copy into a local "OneDrive" folder and returns the
 * same result shape as the Graph driver, so the whole share flow — progress,
 * success, failure and retry — can be demonstrated and tested end to end.
 *
 * `ONEDRIVE_DEMO_FAIL_RATE` forces a proportion of uploads to fail so the
 * failure and retry states can be shown deliberately.
 */
class DemoOneDriveUploader implements OneDriveUploader
{
    public function upload(string $absolutePath, string $remoteName): UploadResult
    {
        if (! is_readable($absolutePath)) {
            return UploadResult::failed('The final output file could not be read from local storage.', $this->driverName());
        }

        $failRate = (float) config('onedrive.demo_fail_rate', 0);

        if ($failRate > 0 && (mt_rand(1, 1000) / 1000) <= $failRate) {
            return UploadResult::failed(
                'The upload could not be completed because the OneDrive service did not respond. Please try again.',
                $this->driverName()
            );
        }

        $folder = trim((string) config('onedrive.folder', 'PharmaVerify/FinalOutput'), '/');
        $target = 'onedrive-demo/'.$folder.'/'.$remoteName;

        Storage::disk('local')->put($target, file_get_contents($absolutePath));

        return UploadResult::ok(
            itemId: 'demo-'.Str::lower(Str::random(16)),
            webUrl: 'local://'.$target,
            driver: $this->driverName(),
        );
    }

    public function driverName(): string
    {
        return 'demo';
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
