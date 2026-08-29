<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * How long an issued access token stays valid.
 *
 * The web application and the handheld terminals are treated separately: a
 * browser can be signed in again in seconds, while a terminal that expires
 * part way through a stock take interrupts a count in progress.
 *
 * Both windows are unset by default, which means tokens do not expire — the
 * behaviour the application has always had. Enabling either is an operational
 * decision, not a technical one, and is recorded as pending in
 * `15-ASSUMPTIONS-DEPENDENCIES.md` D-09.
 */
class TokenExpiry
{
    /** The token name the SPA signs in with; anything else is treated as a device. */
    public const WEB_TOKEN_NAME = 'pharmaverify-web';

    /**
     * The expiry for a token about to be issued, or null if it should not expire.
     */
    public static function for(?string $tokenName): ?DateTimeInterface
    {
        return self::fromMinutes(self::configuredMinutes($tokenName));
    }

    /**
     * The configured window in minutes, or null when expiry is switched off.
     */
    public static function configuredMinutes(?string $tokenName): ?int
    {
        $key = self::isWeb($tokenName) ? 'web_expiry_minutes' : 'device_expiry_minutes';

        $configured = config('security.tokens.'.$key);

        // An unset environment variable arrives as null, and an empty one as ''.
        // Both mean "no expiry" rather than "expire immediately", which is the
        // difference between a working system and one that signs everybody out.
        if ($configured === null || $configured === '') {
            return null;
        }

        $minutes = (int) $configured;

        return $minutes > 0 ? $minutes : null;
    }

    public static function isWeb(?string $tokenName): bool
    {
        return ($tokenName ?: self::WEB_TOKEN_NAME) === self::WEB_TOKEN_NAME;
    }

    private static function fromMinutes(?int $minutes): ?Carbon
    {
        return $minutes === null ? null : Carbon::now()->addMinutes($minutes);
    }
}
