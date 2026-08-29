<?php

namespace App\Services\HhtImport;

use App\Exceptions\BusinessRuleException;
use App\Services\StockReport\StockReportReader;
use App\Support\SessionReference;

/**
 * Reads a workbook exported by the handheld, and works out which kind it is.
 *
 * The device writes two layouts from one exporter, and its source states that
 * column order is part of the interchange contract. Only the headings are
 * relied on here, not their position, so a file that gains a column somewhere
 * in the middle still reads.
 *
 * Detection is by header signature, never by filename — operators rename files
 * freely, and a take imported as an audit would invent a system quantity that
 * the device deliberately refused to supply.
 */
class HhtExcelReader
{
    public const KIND_AUDIT = 'audit';

    public const KIND_STOCK_TAKE = 'stock_take';

    /** Columns without which the layout cannot be read at all. */
    private const REQUIRED = [
        self::KIND_AUDIT => ['inventlocationid', 'auditnumber', 'itemid', 'physicalqty'],
        self::KIND_STOCK_TAKE => ['inventlocationid', 'stocktakenumber', 'itemid', 'physicalqty'],
    ];

    public function __construct(private readonly StockReportReader $reader) {}

    /**
     * The sheet and kind this workbook holds.
     *
     * @return array{kind: string, sheet: string, headings: array<string, int>}
     */
    public function detect(string $path): array
    {
        $sheets = $this->reader->sheetNames($path);

        if ($sheets === []) {
            throw new BusinessRuleException('The file contains no readable sheet.');
        }

        // The export is single-sheet, but a workbook that has been opened and
        // re-saved sometimes gains empty ones. The first sheet that carries a
        // recognisable header wins.
        $lastError = null;

        foreach ($sheets as $sheet) {
            $headings = $this->reader->headings($path, $sheet);

            if ($headings === []) {
                continue;
            }

            try {
                $kind = $this->kindOf($headings);
            } catch (BusinessRuleException $exception) {
                $lastError = $exception;

                continue;
            }

            $missing = array_diff(self::REQUIRED[$kind], array_keys($headings));

            if ($missing !== []) {
                throw new BusinessRuleException(sprintf(
                    'This %s export is missing the required column(s): %s.',
                    $kind === self::KIND_AUDIT ? 'audit' : 'stock take',
                    strtoupper(implode(', ', $missing))
                ));
            }

            return ['kind' => $kind, 'sheet' => $sheet, 'headings' => $headings];
        }

        throw $lastError ?? new BusinessRuleException(
            'This does not look like a handheld export. An audit export carries AUDITNUMBER and SYSTEMQTY; '
            .'a stock take export carries STOCKTAKENUMBER and no system quantity.'
        );
    }

    /**
     * Audit or stock take, decided by which number column is present.
     *
     * `SYSTEMQTY` confirms the reading. The device omits it from a take on
     * purpose — a take is a blind physical count — so its presence alongside
     * a take's number column means the file has been edited into an ambiguous
     * state, and guessing which half to believe is not an improvement on
     * refusing.
     *
     * @param  array<string, int>  $headings
     */
    private function kindOf(array $headings): string
    {
        $hasAuditNumber = isset($headings['auditnumber']);
        $hasTakeNumber = isset($headings['stocktakenumber']);

        if ($hasAuditNumber && $hasTakeNumber) {
            throw new BusinessRuleException(
                'This workbook carries both AUDITNUMBER and STOCKTAKENUMBER, so it cannot be read as either an '
                .'audit or a stock take. Export the two separately.'
            );
        }

        if ($hasAuditNumber) {
            return self::KIND_AUDIT;
        }

        if ($hasTakeNumber) {
            return self::KIND_STOCK_TAKE;
        }

        throw new BusinessRuleException(
            'This does not look like a handheld export. An audit export carries AUDITNUMBER; a stock take export '
            .'carries STOCKTAKENUMBER.'
        );
    }

    /**
     * Every data row, as a map of normalised heading to value.
     *
     * @param  array<string, int>  $headings
     * @param  callable(array<string, mixed>, int): void  $onRow
     */
    public function eachRow(string $path, string $sheet, array $headings, callable $onRow): void
    {
        $columns = StockReportReader::columnLetters(max($headings) + 1);
        $byPosition = array_flip($headings);

        $this->reader->eachRowChunked($path, $sheet, $columns, function (array $cells, int $rowNumber) use ($byPosition, $onRow) {
            $row = [];

            foreach ($byPosition as $position => $heading) {
                $row[$heading] = $cells[$position] ?? null;
            }

            $onRow($row, $rowNumber);
        });
    }

    /**
     * The reference this file belongs to, and the shop codes it mentions.
     *
     * Read ahead of the rows themselves so a file covering two cycles, or one
     * whose reference is malformed, is refused before anything is staged.
     *
     * @param  array<string, int>  $headings
     * @return array{reference: string, locations: array<int, string>}
     */
    public function identify(string $path, string $sheet, array $headings, string $kind): array
    {
        $refColumn = $kind === self::KIND_AUDIT ? 'auditnumber' : 'stocktakenumber';
        $prefix = $kind === self::KIND_AUDIT ? SessionReference::AUDIT : SessionReference::STOCK_TAKE;

        $references = [];
        $locations = [];

        $this->eachRow($path, $sheet, $headings, function (array $row) use (&$references, &$locations, $refColumn) {
            $reference = trim((string) ($row[$refColumn] ?? ''));
            $location = strtoupper(trim((string) ($row['inventlocationid'] ?? '')));

            if ($reference !== '') {
                $references[$reference] = true;
            }

            if ($location !== '') {
                $locations[$location] = true;
            }
        });

        $references = array_keys($references);

        if ($references === []) {
            throw new BusinessRuleException('No reference number could be read from the file.');
        }

        if (count($references) > 1) {
            throw new BusinessRuleException(sprintf(
                'This workbook covers %d different references (%s). Import one at a time so each can be checked '
                .'against what is already recorded.',
                count($references),
                implode(', ', array_slice($references, 0, 4)).(count($references) > 4 ? '…' : '')
            ));
        }

        $reference = $references[0];

        if (! SessionReference::isValid($reference, $prefix)) {
            throw new BusinessRuleException(sprintf(
                'The reference "%s" is not in the expected format. A %s reference reads %s-ddMMyyyy-NNNN.',
                $reference,
                $kind === self::KIND_AUDIT ? 'audit' : 'stock take',
                $prefix
            ));
        }

        return ['reference' => $reference, 'locations' => array_keys($locations)];
    }
}
