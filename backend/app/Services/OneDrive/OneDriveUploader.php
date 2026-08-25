<?php

namespace App\Services\OneDrive;

/**
 * Uploads a generated final output file to OneDrive.
 *
 * Two drivers implement this: the real Microsoft Graph client, and a demo
 * driver used until the client's Azure application registration is available.
 * Nothing else in the application needs to know which one is active.
 */
interface OneDriveUploader
{
    /**
     * @param  string  $absolutePath  Local path of the file to upload
     * @param  string  $remoteName  File name to create in OneDrive
     * @return UploadResult
     */
    public function upload(string $absolutePath, string $remoteName): UploadResult;

    public function driverName(): string;

    /** Whether the driver is configured well enough to attempt an upload. */
    public function isConfigured(): bool;
}
