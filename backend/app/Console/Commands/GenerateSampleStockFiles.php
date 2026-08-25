<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\Shop;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds the sample stock files used for demonstrations and manual testing:
 * one clean file, and one carrying the validation problems real files carry.
 */
class GenerateSampleStockFiles extends Command
{
    protected $signature = 'pharmaverify:sample-stock {--shop=PHM001}';

    protected $description = 'Write sample stock import files to database/sample-data';

    public function handle(): int
    {
        $shopCode = (string) $this->option('shop');
        $shop = Shop::where('shop_code', $shopCode)->first();

        if (! $shop) {
            $this->error("Shop {$shopCode} was not found. Run `php artisan db:seed` first.");

            return self::FAILURE;
        }

        $items = Item::orderBy('product_code')->get();

        if ($items->isEmpty()) {
            $this->error('No items found. Run `php artisan db:seed` first.');

            return self::FAILURE;
        }

        $directory = base_path('../database/sample-data');
        File::ensureDirectoryExists($directory);

        $this->writeValid($directory, $shopCode, $items);
        $this->writeWithErrors($directory, $shopCode, $items);

        $this->info('Sample stock files written to '.(realpath($directory) ?: $directory));

        return self::SUCCESS;
    }

    private function writeValid(string $directory, string $shopCode, $items): void
    {
        $rows = [['Shop', 'Product Code', 'Barcode', 'Product Description', 'System Stock', 'UOM', 'Price', 'Batch', 'Expiry Date', 'Shelf Location']];
        $shelves = ['A-01', 'A-02', 'B-01', 'B-02', 'C-01', 'C-02', 'COLD-01'];
        $index = 0;

        foreach ($items as $item) {
            $index++;

            $rows[] = [
                $shopCode,
                $item->product_code,
                $item->barcode,
                $item->description,
                str_starts_with($item->product_code, 'SUR') ? 20 + ($index * 7) : 60 + ($index * 13),
                $item->uom,
                (float) $item->price,
                sprintf('B%s%03d', substr($shopCode, -2), $index),
                Carbon::create(2027, 1, 1)->addMonths($index * 2)->endOfMonth()->toDateString(),
                $shelves[$index % count($shelves)],
            ];
        }

        $this->write($directory.'/stock-'.strtolower($shopCode).'-valid.xlsx', $rows);
    }

    /**
     * A file carrying the problems real stock files carry, so the import's
     * row-level reporting can be demonstrated.
     */
    private function writeWithErrors(string $directory, string $shopCode, $items): void
    {
        $rows = [['Shop', 'Product Code', 'Barcode', 'Product Description', 'System Stock', 'UOM', 'Price', 'Batch', 'Expiry Date', 'Shelf Location']];

        foreach ($items->take(8) as $index => $item) {
            $rows[] = [
                $shopCode,
                $item->product_code,
                $item->barcode,
                $item->description,
                40 + ($index * 11),
                $item->uom,
                (float) $item->price,
                sprintf('BX%03d', $index + 1),
                Carbon::create(2027, 6, 30)->toDateString(),
                'A-0'.(($index % 3) + 1),
            ];
        }

        $sample = $items->first();

        // Deliberate problems, one per row.
        $rows[] = [$shopCode, 'MED-9001', '8901234590011', 'Ranitidine 150mg Tablet', 'not a number', 'STRIP', 40.0, 'BX901', '2027-06-30', 'A-01'];
        $rows[] = [$shopCode, '', '8901234590028', 'Product with no code', 25, 'STRIP', 30.0, 'BX902', '2027-06-30', 'A-01'];
        $rows[] = [$shopCode, 'MED-9003', '8901234590035', 'Negative quantity product', -12, 'STRIP', 55.0, 'BX903', '2027-06-30', 'A-02'];
        $rows[] = [$shopCode, 'MED-9004', '8901234590042', '', 30, 'STRIP', 60.0, 'BX904', '2027-06-30', 'A-02'];
        $rows[] = [$shopCode, $sample->product_code, $sample->barcode, $sample->description, 15, $sample->uom, (float) $sample->price, 'BX001', '2027-06-30', 'A-01'];
        $rows[] = ['PHM999', 'MED-9006', '8901234590066', 'Row belonging to another shop', 20, 'STRIP', 45.0, 'BX906', '2027-06-30', 'B-01'];

        $this->write($directory.'/stock-'.strtolower($shopCode).'-with-errors.xlsx', $rows);
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function write(string $path, array $rows): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Stock');

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValue([$columnIndex + 1, $rowIndex + 1], $value);
            }
        }

        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($rows[0]));

        $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0F5D4C');

        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $this->line('  '.basename($path).'  ('.(count($rows) - 1).' data rows)');
    }
}
