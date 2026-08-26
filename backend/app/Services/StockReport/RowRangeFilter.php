<?php

namespace App\Services\StockReport;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/** Parses only rows within an inclusive range — used to peek at headings. */
class RowRangeFilter implements IReadFilter
{
    public function __construct(private readonly int $from, private readonly int $to) {}

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $row >= $this->from && $row <= $this->to;
    }
}
