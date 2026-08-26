<?php

namespace App\Services\StockReport;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Parses only the named columns within a row window.
 *
 * Combining both limits lets a very large sheet be walked a slice at a time, so
 * peak memory is set by the chunk size rather than by the size of the sheet.
 */
class ChunkFilter implements IReadFilter
{
    /** @var array<string, true> */
    private array $columns;

    /**
     * @param  array<int, string>  $columns
     */
    public function __construct(array $columns, private readonly int $from, private readonly int $to)
    {
        $this->columns = array_fill_keys(array_map('strtoupper', $columns), true);
    }

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        if ($row < $this->from || $row > $this->to) {
            return false;
        }

        return isset($this->columns[strtoupper($columnAddress)]);
    }
}
