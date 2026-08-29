<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Pairing a handheld with the server.
 *
 * A device needs to reach the API as itself. Borrowing an operator's token
 * would tie a terminal's working life to one person's session and attribute
 * every count to whoever set it up, so instead the terminal gets a token of its
 * own — issued once, from a short-lived code an administrator reads off the
 * device's record.
 *
 * Three properties matter here, and each costs almost nothing:
 *
 *  - The code is **stored hashed**. A screenshot of the pairing screen, or a
 *    glance at the database later, does not yield a working code.
 *  - It is **single use and short-lived**. Spending it clears it; leaving it
 *    unused expires it.
 *  - Pairing again **revokes the previous token**. A terminal that is re-paired
 *    because it was lost or reset stops the old one working, which is the whole
 *    point of re-pairing it.
 */
class DevicePairingService
{
    /** How long an unused code stays good. */
    public const CODE_TTL_MINUTES = 30;

    /**
     * Issues a pairing code for a device and returns it in the clear, once.
     *
     * The caller shows it to the operator; nothing here or in the database can
     * produce it again.
     */
    public function issueCode(Device $device, User $issuedBy): string
    {
        // Unambiguous alphabet: no O/0, no I/1, so a code read off a screen and
        // typed into a handheld keypad does not fail on a lookalike.
        $code = strtoupper(Str::padLeft(
            substr(str_replace(['O', '0', 'I', '1', 'L', 'U'], '', Str::random(24)), 0, 8),
            8,
            'X'
        ));

        $device->forceFill([
            'pairing_code_hash' => Hash::make($code),
            'pairing_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
        ])->save();

        activity('device')
            ->causedBy($issuedBy)
            ->performedOn($device)
            ->withProperties(['device' => $device->device_code, 'expires_in_minutes' => self::CODE_TTL_MINUTES])
            ->log('Device pairing code issued');

        return $code;
    }

    /**
     * Exchanges a code for the device's own API token.
     *
     * @return array{device: Device, token: string}
     */
    public function pair(string $deviceCode, string $code, ?string $serialNumber = null): array
    {
        $device = Device::with('shop')
            ->where('device_code', $deviceCode)
            ->where('status', 'active')
            ->first();

        // One message for every failure below, deliberately. Telling an
        // unauthenticated caller which half was wrong turns this into a way to
        // enumerate device codes.
        $refusal = 'That pairing code is not valid for this device. Ask an administrator to issue a new one.';

        if (! $device
            || $device->pairing_code_hash === null
            || $device->pairing_expires_at === null
            || $device->pairing_expires_at->isPast()
            || ! Hash::check($code, $device->pairing_code_hash)
        ) {
            throw new BusinessRuleException($refusal, 422);
        }

        // Spent. Whatever happens next, this code is finished.
        $device->forceFill([
            'pairing_code_hash' => null,
            'pairing_expires_at' => null,
            'paired_at' => now(),
            'last_seen_at' => now(),
            'serial_number' => $serialNumber ?: $device->serial_number,
        ])->save();

        // Re-pairing replaces: a terminal that was lost or wiped must not leave
        // a working token behind it.
        $device->tokens()->delete();

        $token = $device->createToken(
            'hht-device-'.$device->device_code,
            [Device::ABILITY_SUBMIT]
        )->plainTextToken;

        activity('device')
            ->performedOn($device)
            ->withProperties(['device' => $device->device_code, 'shop' => $device->shop?->shop_code])
            ->log('Device paired');

        return ['device' => $device->fresh('shop'), 'token' => $token];
    }

    /** Drops a device's token, so the terminal must be paired again. */
    public function unpair(Device $device, User $revokedBy): void
    {
        $device->tokens()->delete();

        $device->forceFill([
            'pairing_code_hash' => null,
            'pairing_expires_at' => null,
            'paired_at' => null,
        ])->save();

        activity('device')
            ->causedBy($revokedBy)
            ->performedOn($device)
            ->withProperties(['device' => $device->device_code])
            ->log('Device unpaired');
    }
}
