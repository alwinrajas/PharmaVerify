<?php

namespace App\Services\StockReport;

use App\Exceptions\BusinessRuleException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use Throwable;

/**
 * Reads the business Stock Report workbook without loading it whole.
 *
 * The report is a Dynamics AX export of three sheets — roughly 152,000 rows
 * across them — and a naive read of the file needs over half a gigabyte, which
 * is more than the process is given. Three things keep it small:
 *
 *   1. Only the sheet being worked on is loaded (`setLoadSheetsOnly`).
 *   2. Only the columns actually used are parsed (a column read filter).
 *   3. Cell styling is skipped (`setReadDataOnly`), which is most of the weight.
 *
 * The two lookup sheets are never kept in full. The stock sheet is read first to
 * learn which products and batches are actually referenced, and only those keys
 * are retained while the much larger sheets stream past.
 */
class StockReportReader
{
    /** The sheets a Stock Report must contain. */
    public const SHEET_STOCK = 'stock';
    public const SHEET_BATCHES = 'all batches';
    public const SHEET_ITEMS = 'Item Master';

    /**
     * Required columns per sheet, as normalised headings.
     *
     * These are the mappings the business has confirmed it needs, so a report
     * missing one is rejected before anything is written rather than imported
     * with a silently empty column. HIGHERQTY is deliberately absent: whole
     * quantity is `LOWERQTY / FACTOR`, so a report that omits it can still be
     * imported correctly from the two columns that are required.
     *
     * @var array<string, array<int, string>>
     */
    private const REQUIRED_COLUMNS = [
        self::SHEET_STOCK => ['inventlocationid', 'itemid', 'inventbatchid', 'lowerqty', 'totalcost'],
        self::SHEET_BATCHES => ['itemid', 'inventbatchid', 'itembarcode'],
        self::SHEET_ITEMS => ['itemid', 'itemname', 'salesprice', 'factor', 'globaltradeitemnumber'],
    ];

    /**
     * Does this workbook look like the business Stock Report?
     *
     * Used to decide between this workflow and the older flat single-sheet
     * import, so both file shapes keep working.
     */
    /**
     * The workbook's sheet names, as written.
     *
     * @return array<int, string>
     */
    public function sheetNames(string $path): array
    {
        try {
            return array_values(array_map(
                fn (array $info) => (string) $info['worksheetName'],
                $this->reader($path)->listWorksheetInfo($path)
            ));
        } catch (Throwable) {
            return [];
        }
    }

    public function looksLikeStockReport(string $path): bool
    {
        $names = array_map(
            fn (string $name) => strtolower(trim($name)),
            $this->sheetNames($path)
        );

        if ($names === []) {
            return false;
        }

        // Stock and Item Master identify the report; the batches sheet is
        // optional in newer exports and says nothing either way.
        foreach ([self::SHEET_STOCK, self::SHEET_ITEMS] as $required) {
            if (! in_array(strtolower($required), $names, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Confirms the workbook has the sheets and columns the import depends on,
     * before any of it is processed.
     *
     * @return array<string, string> the workbook's actual sheet names, keyed by the canonical name
     */
    public function assertStructure(string $path): array
    {
        try {
            $info = $this->reader($path)->listWorksheetInfo($path);
        } catch (Throwable) {
            throw new BusinessRuleException(
                'The selected file could not be opened. Please confirm it is a valid Excel workbook.'
            );
        }

        $actual = [];

        foreach ($info as $sheet) {
            $actual[strtolower(trim($sheet['worksheetName']))] = $sheet['worksheetName'];
        }

        $resolved = [];
        $missing = [];

        // "all batches" is no longer part of the export the business sends:
        // newer files carry only "Stock" and "Item Master", with the GTIN in
        // the item master doing the identifying that the batch sheet's
        // ITEMBARCODE used to do. Older three-sheet files still arrive, so the
        // sheet stays understood — it just stopped being demanded.
        foreach ([self::SHEET_STOCK, self::SHEET_ITEMS] as $required) {
            $key = strtolower($required);

            if (! isset($actual[$key])) {
                $missing[] = $required;

                continue;
            }

            $resolved[$required] = $actual[$key];
        }

        $batchesKey = strtolower(self::SHEET_BATCHES);

        if (isset($actual[$batchesKey])) {
            $resolved[self::SHEET_BATCHES] = $actual[$batchesKey];
        }

        if ($missing !== []) {
            throw new BusinessRuleException(sprintf(
                'This does not look like a Stock Report. The workbook is missing the sheet(s): %s. A Stock Report contains "%s" and "%s" (an "%s" sheet is used when present, but is not required).',
                implode(', ', array_map(fn ($s) => '"'.$s.'"', $missing)),
                self::SHEET_STOCK,
                self::SHEET_ITEMS,
                self::SHEET_BATCHES
            ));
        }

        foreach ($resolved as $canonical => $sheetName) {
            $this->assertColumns($path, $canonical, $sheetName);
        }

        return $resolved;
    }

    /**
     * Column positions for a sheet, keyed by normalised heading.
     *
     * @return array<string, int>
     */
    public function headings(string $path, string $sheetName): array
    {
        $reader = $this->reader($path);
        $reader->setLoadSheetsOnly($sheetName);
        $reader->setReadFilter(new RowRangeFilter(1, 1));

        $sheet = $reader->load($path)->getActiveSheet();
        $row = $sheet->toArray(null, true, false, false)[0] ?? [];

        $map = [];

        foreach ($row as $position => $value) {
            $heading = $this->normalise((string) ($value ?? ''));

            if ($heading !== '' && ! isset($map[$heading])) {
                $map[$heading] = $position;
            }
        }

        return $map;
    }

    /**
     * Walks a sheet's data rows, handing each one to the callback.
     *
     * Only the requested columns are parsed, and the loaded sheet is released
     * as soon as the walk finishes, so peak memory stays proportional to what
     * the caller keeps rather than to the size of the sheet.
     *
     * @param  array<int, string>  $columnLetters
     * @param  callable(array<int, mixed>, int): void  $onRow
     */
    public function eachRow(string $path, string $sheetName, array $columnLetters, callable $onRow): void
    {
        $reader = $this->reader($path);
        $reader->setLoadSheetsOnly($sheetName);
        $reader->setReadFilter(new ColumnFilter($columnLetters));

        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        try {
            $first = true;

            foreach ($sheet->getRowIterator() as $row) {
                if ($first) {
                    $first = false;

                    continue;
                }

                $cells = [];
                $iterator = $row->getCellIterator();
                $iterator->setIterateOnlyExistingCells(false);

                foreach ($iterator as $cell) {
                    $cells[] = $cell?->getValue();
                }

                $onRow($cells, $row->getRowIndex());
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $sheet);
        }
    }

    /**
     * Walks a sheet a slice at a time.
     *
     * Peak memory is then governed by the chunk size rather than by the sheet,
     * which is what keeps a growing report within the process limit. Each slice
     * costs another parse of the file, so the chunk wants to be large enough to
     * keep that overhead small and small enough to stay comfortable — 25,000
     * rows of a few columns sits well inside a default limit.
     *
     * @param  array<int, string>  $columnLetters
     * @param  callable(array<int, mixed>, int): void  $onRow
     */
    public function eachRowChunked(
        string $path,
        string $sheetName,
        array $columnLetters,
        callable $onRow,
        int $chunkSize = 25000
    ): void {
        $totalRows = $this->rowCount($path, $sheetName) + 1; // include the heading row

        for ($start = 2; $start <= $totalRows; $start += $chunkSize) {
            $end = min($start + $chunkSize - 1, $totalRows);

            $reader = $this->reader($path);
            $reader->setLoadSheetsOnly($sheetName);
            $reader->setReadFilter(new ChunkFilter($columnLetters, $start, $end));

            $spreadsheet = $reader->load($path);
            $sheet = $spreadsheet->getActiveSheet();

            try {
                foreach ($sheet->getRowIterator($start, $end) as $row) {
                    $cells = [];
                    $iterator = $row->getCellIterator();
                    $iterator->setIterateOnlyExistingCells(false);

                    foreach ($iterator as $cell) {
                        $cells[] = $cell?->getValue();
                    }

                    $onRow($cells, $row->getRowIndex());
                }
            } finally {
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet, $sheet, $reader);
            }
        }
    }

    /** The number of data rows in a sheet, without loading its contents. */
    public function rowCount(string $path, string $sheetName): int
    {
        foreach ($this->reader($path)->listWorksheetInfo($path) as $info) {
            if ($info['worksheetName'] === $sheetName) {
                return max(0, $info['totalRows'] - 1);
            }
        }

        return 0;
    }

    public function normalise(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $value) ?? '');
    }

    private function assertColumns(string $path, string $canonical, string $sheetName): void
    {
        $headings = $this->headings($path, $sheetName);
        $missing = array_diff(self::REQUIRED_COLUMNS[$canonical], array_keys($headings));

        if ($missing !== []) {
            throw new BusinessRuleException(sprintf(
                'The "%s" sheet is missing the required column(s): %s.',
                $sheetName,
                strtoupper(implode(', ', $missing))
            ));
        }
    }

    private function reader(string $path): IReader
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        return $reader;
    }

    /** Column letters A.. for a zero-based count. */
    public static function columnLetters(int $count): array
    {
        return array_map(
            fn (int $index) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1),
            range(0, max(0, $count - 1))
        );
    }
}
