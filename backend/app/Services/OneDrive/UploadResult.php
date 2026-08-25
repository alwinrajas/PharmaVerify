<?php

namespace App\Services\OneDrive;

class UploadResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $itemId = null,
        public readonly ?string $webUrl = null,
        public readonly ?string $error = null,
        public readonly string $driver = 'demo',
    ) {}

    public static function ok(string $itemId, ?string $webUrl, string $driver): self
    {
        return new self(true, $itemId, $webUrl, null, $driver);
    }

    public static function failed(string $error, string $driver): self
    {
        return new self(false, null, null, $error, $driver);
    }
}
