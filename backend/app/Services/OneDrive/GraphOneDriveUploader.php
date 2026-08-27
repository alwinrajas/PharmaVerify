<?php

namespace App\Services\OneDrive;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Uploads to OneDrive through the Microsoft Graph API using the client
 * credentials flow (an Azure app registration with `Files.ReadWrite.All`
 * application permission granted by an administrator).
 *
 * Files up to 4 MB are sent in a single PUT; anything larger goes through an
 * upload session in 5 MB chunks, which is what Graph requires.
 */
class GraphOneDriveUploader implements OneDriveUploader
{
    private const SIMPLE_UPLOAD_LIMIT = 4 * 1024 * 1024;

    private const CHUNK_SIZE = 5 * 1024 * 1024;

    public function upload(string $absolutePath, string $remoteName): UploadResult
    {
        if (! $this->isConfigured()) {
            return UploadResult::failed(
                'OneDrive has not been configured yet. Ask your administrator to supply the Microsoft 365 application details.',
                $this->driverName()
            );
        }

        if (! is_readable($absolutePath)) {
            return UploadResult::failed('The final output file could not be read from local storage.', $this->driverName());
        }

        try {
            $token = $this->accessToken();

            if ($token === null) {
                return UploadResult::failed(
                    'PharmaVerify could not sign in to Microsoft 365. Please check the OneDrive configuration.',
                    $this->driverName()
                );
            }

            $size = filesize($absolutePath) ?: 0;

            return $size <= self::SIMPLE_UPLOAD_LIMIT
                ? $this->simpleUpload($token, $absolutePath, $remoteName)
                : $this->chunkedUpload($token, $absolutePath, $remoteName, $size);
        } catch (Throwable $e) {
            report($e);

            return UploadResult::failed(
                'The upload to OneDrive could not be completed. Please try again.',
                $this->driverName()
            );
        }
    }

    public function driverName(): string
    {
        return 'graph';
    }

    public function isConfigured(): bool
    {
        $hasCredentials = ! $this->missing('tenant_id')
            && ! $this->missing('client_id')
            && ! $this->missing('client_secret');

        // Either a drive id or a user principal identifies the destination.
        $hasTarget = ! $this->missing('drive_id') || ! $this->missing('user_principal');

        return $hasCredentials && $hasTarget;
    }

    /**
     * Whether a configuration value is absent for our purposes.
     *
     * The shipped .env.example carries YOUR_TENANT_ID and friends. Treating one
     * of those as a real value sends a request to a tenant that cannot exist,
     * and the administrator is then told that sign-in failed when the truth is
     * that the configuration was never finished.
     */
    private function missing(string $key): bool
    {
        $value = trim((string) config('onedrive.'.$key));

        return $value === '' || str_starts_with($value, 'YOUR_');
    }

    private function accessToken(): ?string
    {
        $cached = Cache::get($this->tokenCacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()
            ->timeout(30)
            ->post(sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/token', config('onedrive.tenant_id')), [
                'client_id' => config('onedrive.client_id'),
                'client_secret' => config('onedrive.client_secret'),
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]);

        if (! $response->successful()) {
            // The user is told something readable; the administrator needs the
            // reason Microsoft actually gave, or there is nothing to act on.
            // Azure returns a diagnosis such as "AADSTS7000215: Invalid client
            // secret provided" and never echoes the secret itself.
            Log::warning('OneDrive: Microsoft 365 refused the sign-in request.', [
                'status' => $response->status(),
                'error' => $response->json('error'),
                'error_description' => Str::limit((string) $response->json('error_description'), 400),
                'tenant_id' => config('onedrive.tenant_id'),
                'client_id' => config('onedrive.client_id'),
            ]);

            return null;
        }

        $token = (string) ($response->json('access_token') ?? '');

        if ($token === '') {
            Log::warning('OneDrive: Microsoft 365 accepted the sign-in but returned no access token.', [
                'status' => $response->status(),
            ]);

            return null;
        }

        // Expire a few minutes early so a token cannot lapse part way through
        // a chunked upload.
        $lifetime = (int) ($response->json('expires_in') ?? 3600);
        Cache::put($this->tokenCacheKey(), $token, max(60, $lifetime - 300));

        return $token;
    }

    /**
     * Keyed by tenant and application, so changing either takes effect at once.
     * The secret is never part of the key.
     */
    private function tokenCacheKey(): string
    {
        return 'onedrive.graph.token.'.sha1(
            (string) config('onedrive.tenant_id').'|'.(string) config('onedrive.client_id')
        );
    }

    /** Drops a token Graph has rejected, so the next attempt signs in afresh. */
    private function forgetToken(): void
    {
        Cache::forget($this->tokenCacheKey());
    }

    private function simpleUpload(string $token, string $path, string $remoteName): UploadResult
    {
        $response = Http::withToken($token)
            ->withBody(file_get_contents($path), 'application/octet-stream')
            ->timeout(120)
            ->put($this->itemPath($remoteName).':/content');

        if (! $response->successful()) {
            $this->forgetRejectedToken($response->status());

            return UploadResult::failed($this->readableError($response->json()), $this->driverName());
        }

        return UploadResult::ok(
            itemId: (string) $response->json('id'),
            webUrl: $response->json('webUrl'),
            driver: $this->driverName(),
        );
    }

    private function chunkedUpload(string $token, string $path, string $remoteName, int $size): UploadResult
    {
        $session = Http::withToken($token)
            ->timeout(60)
            ->post($this->itemPath($remoteName).':/createUploadSession', [
                'item' => ['@microsoft.graph.conflictBehavior' => 'replace'],
            ]);

        if (! $session->successful()) {
            $this->forgetRejectedToken($session->status());

            return UploadResult::failed($this->readableError($session->json()), $this->driverName());
        }

        $uploadUrl = (string) $session->json('uploadUrl');
        $handle = fopen($path, 'rb');
        $offset = 0;
        $last = null;

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, self::CHUNK_SIZE);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $length = strlen($chunk);

                $last = Http::withBody($chunk, 'application/octet-stream')
                    ->withHeaders([
                        'Content-Length' => (string) $length,
                        'Content-Range' => sprintf('bytes %d-%d/%d', $offset, $offset + $length - 1, $size),
                    ])
                    ->timeout(180)
                    ->put($uploadUrl);

                if (! $last->successful()) {
                    $this->forgetRejectedToken($last->status());

                    return UploadResult::failed($this->readableError($last->json()), $this->driverName());
                }

                $offset += $length;
            }
        } finally {
            fclose($handle);
        }

        return UploadResult::ok(
            itemId: (string) ($last?->json('id') ?? ''),
            webUrl: $last?->json('webUrl'),
            driver: $this->driverName(),
        );
    }

    private function itemPath(string $remoteName): string
    {
        $folder = trim((string) config('onedrive.folder', 'PharmaVerify/FinalOutput'), '/');
        $encodedFolder = implode('/', array_map('rawurlencode', explode('/', $folder)));
        $encodedName = rawurlencode($remoteName);

        $root = filled(config('onedrive.drive_id'))
            ? sprintf('https://graph.microsoft.com/v1.0/drives/%s/root', config('onedrive.drive_id'))
            : sprintf('https://graph.microsoft.com/v1.0/users/%s/drive/root', rawurlencode((string) config('onedrive.user_principal')));

        return sprintf('%s:/%s/%s', $root, $encodedFolder, $encodedName);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function readableError(?array $body): string
    {
        $code = $body['error']['code'] ?? null;

        return match ($code) {
            'accessDenied' => 'OneDrive refused the upload. The application does not have permission to write to this folder.',
            'quotaLimitReached', 'insufficientStorage' => 'The OneDrive account does not have enough space for this file.',
            'itemNotFound' => 'The destination folder could not be found in OneDrive.',
            'activityLimitReached' => 'Microsoft 365 received too many requests just now. Please wait a moment and try again.',
            'unauthenticated', 'invalidAuthenticationToken' => 'The Microsoft 365 sign-in expired during the upload. Please try again.',
            'nameAlreadyExists' => 'A file with this name already exists in the destination folder.',
            'malwareDetected' => 'Microsoft 365 blocked this file during the upload.',
            'resourceModified' => 'The destination changed while the file was uploading. Please try again.',
            'invalidRequest' => 'Microsoft 365 rejected the request. Please check the OneDrive folder configuration.',
            default => 'The upload to OneDrive was rejected by Microsoft 365. Please try again or contact your administrator.',
        };
    }

    /**
     * A 401 means the token we hold is no longer usable. Leaving it cached
     * would make every later attempt fail the same way until it expired.
     */
    private function forgetRejectedToken(int $status): void
    {
        if ($status === 401) {
            $this->forgetToken();
        }
    }
}
