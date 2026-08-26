<?php

namespace App\Services\StockReport;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Parses only the named columns.
 *
 * Item Master carries fifteen columns and the import uses four of them; loading
 * only those is the difference between holding the sheet comfortably and
 * exhausting the memory limit.
 */
class ColumnFilter implements IReadFilter
{
    /** @var array<string, true> */
    private array $columns;

    /**
     * @param  array<int, string>  $columns  column letters, e.g. ['A', 'B', 'D']
     */
    public function __construct(array $columns)
    {
        $this->columns = array_fill_keys(array_map('strtoupper', $columns), true);
    }

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return isset($this->columns[strtoupper($columnAddress)]);
    }
}
