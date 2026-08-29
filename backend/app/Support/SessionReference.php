<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The reference format the handheld issues, and how to read one.
 *
 *   AUD-11082026-0002   an audit
 *   STK-17082026-0007   a stock take
 *
 * Day-first date, then a four-digit sequence. The sequence runs per shop and
 * per workflow, so `AUD-11082026-0001` exists once for P001 and again for P002
 * — a reference only means anything alongside the shop it belongs to, which is
 * why nothing here treats one as globally unique.
 *
 * PharmaVerify does not normally mint these; the device does, and the importer
 * records what it is given. The builder exists for the two cases where this
 * side has to produce one: deriving a display reference for an audit that
 * predates the format, and numbering a stock take raised in the web
 * application.
 */
final class SessionReference
{
    public const AUDIT = 'AUD';

    public const STOCK_TAKE = 'STK';

    /** Day-first, matching how the date is written everywhere else. */
    private const DATE_FORMAT = 'dmY';

    private const PATTERN = '/^(AUD|STK)-(\d{8})-(\d{4})$/';

    public static function build(string $prefix, Carbon|string $date, int $sequence): string
    {
        $on = $date instanceof Carbon ? $date : Carbon::parse($date);

        return sprintf('%s-%s-%04d', $prefix, $on->format(self::DATE_FORMAT), $sequence);
    }

    public static function forAudit(Carbon|string $date, int $sequence): string
    {
        return self::build(self::AUDIT, $date, $sequence);
    }

    public static function forStockTake(Carbon|string $date, int $sequence): string
    {
        return self::build(self::STOCK_TAKE, $date, $sequence);
    }

    /** Is this a well-formed reference of the given kind, or of either kind? */
    public static function isValid(string $reference, ?string $prefix = null): bool
    {
        if (preg_match(self::PATTERN, trim($reference), $matches) !== 1) {
            return false;
        }

        return $prefix === null || $matches[1] === $prefix;
    }

    /**
     * The parts of a reference, or null if it is not one.
     *
     * @return array{prefix: string, date: string, sequence: int}|null
     */
    public static function parse(string $reference): ?array
    {
        if (preg_match(self::PATTERN, trim($reference), $matches) !== 1) {
            return null;
        }

        return [
            'prefix' => $matches[1],
            'date' => $matches[2],
            'sequence' => (int) $matches[3],
        ];
    }

    /** The numeric tail, which is what PharmaVerify stores as the number. */
    public static function sequenceOf(string $reference): ?int
    {
        return self::parse($reference)['sequence'] ?? null;
    }
}
