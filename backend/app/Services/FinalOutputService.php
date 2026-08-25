<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Audit;
use App\Models\AuditLine;
use App\Models\FinalOutput;
use App\Models\User;
use App\Services\OneDrive\OneDriveUploader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Produces the file that closes out an audit, and shares it to OneDrive.
 *
 * The upload is never automatic: the file is generated when the user asks for
 * it, and it leaves the building only when the user clicks Share to OneDrive.
 */
class FinalOutputService
{
    private const DIRECTORY = 'final-output';

    public function __construct(private readonly OneDriveUploader $uploader) {}

    public function generate(Audit $audit, User $user): FinalOutput
    {
        $audit->loadMissing(['shop', 'device']);

        $lines = $audit->lines()->orderBy('product_code')->get();

        if ($lines->isEmpty()) {
            throw new BusinessRuleException('This audit does not contain any lines, so a final output cannot be produced.');
        }

        $pendingVerification = $lines->where('verification_status', AuditLine::VERIFICATION_PENDING)->count();
        $withVariance = $lines->where('variance_qty', '!=', 0);
        $pendingAdjustment = $withVariance->where('adjustment_status', AuditLine::ADJUSTMENT_NOT_ADJUSTED)->count();

        $fileName = sprintf(
            '%s_%s_Audit-%d_%s.xlsx',
            $audit->shop?->shop_code ?? 'SHOP',
            $audit->device?->device_code ?? 'DEVICE',
            $audit->audit_number,
            now()->format('Ymd-His')
        );

        $relativePath = self::DIRECTORY.'/'.$fileName;

        Storage::disk('local')->put($relativePath, $this->buildWorkbook($audit, $lines, $user));

        return DB::transaction(function () use ($audit, $user, $fileName, $relativePath, $lines, $pendingVerification, $pendingAdjustment) {
            $output = FinalOutput::create([
                'shop_id' => $audit->shop_id,
                'audit_id' => $audit->id,
                'file_name' => $fileName,
                'file_path' => $relativePath,
                'record_count' => $lines->count(),
                'verification_status' => $pendingVerification === 0 ? 'verified' : 'partially_verified',
                'adjustment_status' => $pendingAdjustment === 0 ? 'completed' : 'pending',
                'onedrive_status' => FinalOutput::ONEDRIVE_NOT_UPLOADED,
                'generated_by' => $user->id,
                'generated_at' => now(),
            ]);

            activity('final_output')
                ->causedBy($user)
                ->performedOn($output)
                ->withProperties([
                    'shop' => $audit->shop?->shop_code,
                    'audit_number' => $audit->audit_number,
                    'records' => $lines->count(),
                ])
                ->log('Final output generated');

            return $output->fresh(['shop', 'audit.device', 'generatedBy']);
        });
    }

    /**
     * Sends an already generated file to OneDrive. Called only from the
     * explicit Share to OneDrive action.
     */
    public function shareToOneDrive(FinalOutput $output, User $user): FinalOutput
    {
        if (! $output->file_path || ! Storage::disk('local')->exists($output->file_path)) {
            throw new BusinessRuleException('The final output file is no longer available. Please generate it again.');
        }

        if ($output->onedrive_status === FinalOutput::ONEDRIVE_UPLOADED) {
            throw new BusinessRuleException('This final output has already been shared to OneDrive.');
        }

        $output->update([
            'onedrive_status' => FinalOutput::ONEDRIVE_UPLOADING,
            'upload_attempts' => $output->upload_attempts + 1,
            'last_error' => null,
        ]);

        $result = $this->uploader->upload(
            Storage::disk('local')->path($output->file_path),
            $output->file_name
        );

        if (! $result->success) {
            $output->update([
                'onedrive_status' => FinalOutput::ONEDRIVE_FAILED,
                'last_error' => $result->error,
            ]);

            activity('onedrive')
                ->causedBy($user)
                ->performedOn($output)
                ->withProperties(['driver' => $result->driver, 'error' => $result->error])
                ->log('OneDrive upload failed');

            throw new BusinessRuleException($result->error ?? 'The upload to OneDrive could not be completed.', 502);
        }

        $output->update([
            'onedrive_status' => FinalOutput::ONEDRIVE_UPLOADED,
            'onedrive_item_id' => $result->itemId,
            'onedrive_url' => $result->webUrl,
            'uploaded_at' => now(),
            'last_error' => null,
        ]);

        activity('onedrive')
            ->causedBy($user)
            ->performedOn($output)
            ->withProperties([
                'driver' => $result->driver,
                'file' => $output->file_name,
                'item_id' => $result->itemId,
            ])
            ->log('Final output shared to OneDrive');

        return $output->fresh(['shop', 'audit.device', 'generatedBy']);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AuditLine>  $lines
     */
    private function buildWorkbook(Audit $audit, $lines, User $user): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Final Output');

        $sheet->setCellValue('A1', 'Pharmacy Stock Verification - Final Output');
        $sheet->mergeCells('A1:M1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $header = [
            ['Shop', ($audit->shop?->shop_code ?? '-').' - '.($audit->shop?->shop_name ?? '')],
            ['Device', $audit->device?->device_code ?? '-'],
            ['Audit Number', $audit->audit_number],
            ['Audit Date', $audit->audit_date?->format('d M Y')],
            ['Submitted', $audit->submitted_at?->format('d M Y H:i')],
            ['Counted By', $audit->hht_user ?? '-'],
            ['Records', $lines->count()],
            ['Generated By', $user->name],
            ['Generated On', now()->format('d M Y H:i')],
        ];

        $row = 3;

        foreach ($header as [$label, $value]) {
            $sheet->setCellValue('A'.$row, $label);
            $sheet->getStyle('A'.$row)->getFont()->setBold(true);
            $sheet->setCellValue('B'.$row, $value);
            $row++;
        }

        $row++;
        $headerRow = $row;

        $columns = [
            'Product Code', 'Barcode', 'Product Description', 'Batch', 'Expiry', 'Shelf',
            'UOM', 'Price', 'System Qty', 'Physical Qty', 'Variance', 'Verification', 'Adjustment',
        ];

        foreach ($columns as $index => $label) {
            $sheet->setCellValue([$index + 1, $headerRow], $label);
        }

        $sheet->getStyle('A'.$headerRow.':M'.$headerRow)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A'.$headerRow.':M'.$headerRow)->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0F5D4C');

        $row = $headerRow + 1;

        foreach ($lines as $line) {
            $values = [
                $line->product_code,
                $line->barcode,
                $line->description,
                $line->batch,
                $line->expiry_date?->format('Y-m-d'),
                $line->shelf_location,
                $line->uom,
                (float) $line->price,
                (float) $line->system_qty,
                (float) $line->physical_qty,
                (float) $line->variance_qty,
                $line->verification_status,
                $line->adjustment_status,
            ];

            foreach ($values as $index => $value) {
                $sheet->setCellValue([$index + 1, $row], $value ?? '');
            }

            $row++;
        }

        $sheet->getStyle('A'.$headerRow.':M'.($row - 1))->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D6DEDD');
        $sheet->getStyle('H'.($headerRow + 1).':K'.($row - 1))->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        foreach (range('A', 'M') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $sheet->freezePane('A'.($headerRow + 1));

        $temporary = tempnam(sys_get_temp_dir(), 'pv-final-');
        (new Xlsx($spreadsheet))->save($temporary);
        $contents = file_get_contents($temporary);

        $spreadsheet->disconnectWorksheets();
        @unlink($temporary);

        return $contents;
    }
}
