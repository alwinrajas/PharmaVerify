<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditResource;
use App\Http\Resources\StockTakeSessionResource;
use App\Models\Device;
use App\Services\HhtImport\AuditExcelImportService;
use App\Services\HhtImport\HhtExcelReader;
use App\Services\HhtImport\StockTakeExcelImportService;
use App\Support\ApiResponse;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives the workbooks a handheld exports.
 *
 * The device has no network path, so a completed count arrives as a file. The
 * kind is decided from the file's own headings rather than from anything the
 * user says, so an audit cannot be filed as a stock take by mistake.
 *
 * Nothing is written until the user has seen what the file would do. `preview`
 * runs exactly the same reading and validation as `store`; only the transaction
 * is missing.
 */
class HhtImportController extends Controller
{
    public function __construct(
        private readonly HhtExcelReader $reader,
        private readonly AuditExcelImportService $audits,
        private readonly StockTakeExcelImportService $takes,
    ) {}

    /** Reads and judges the file. Writes nothing. */
    public function preview(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::HHT_IMPORT) || abort(403);

        $validated = $this->validateUpload($request);
        $file = $request->file('file');

        set_time_limit(0);

        $kind = $this->reader->detect($file->getRealPath())['kind'];

        if ($kind === HhtExcelReader::KIND_AUDIT) {
            $device = $this->resolveDevice($request, $validated);

            return ApiResponse::success($this->audits->preview($file, $device), null, ['kind' => $kind]);
        }

        return ApiResponse::success($this->takes->preview($file), null, ['kind' => $kind]);
    }

    /** Commits the file in one transaction. */
    public function store(Request $request): JsonResponse
    {
        $request->user()->can(Permissions::HHT_IMPORT) || abort(403);

        $validated = $this->validateUpload($request);
        $file = $request->file('file');

        set_time_limit(0);

        $kind = $this->reader->detect($file->getRealPath())['kind'];

        if ($kind === HhtExcelReader::KIND_STOCK_TAKE) {
            $result = $this->takes->import($file, $request->user());

            return ApiResponse::success(
                new StockTakeSessionResource($result['session']),
                $result['duplicate']
                    ? sprintf('%s has already been imported. Nothing was changed.', $result['summary']['reference'])
                    : sprintf(
                        'Stock take %s imported. %s line(s) recorded.',
                        $result['summary']['reference'],
                        number_format($result['summary']['imported'])
                    ),
                ['kind' => $kind, 'summary' => $result['summary'], 'duplicate' => $result['duplicate']],
                $result['duplicate'] ? 200 : 201
            );
        }

        // An audit belongs to a device, and the export does not name one — the
        // operator says which handheld it came from, so shop + device + audit
        // number stays the identity it has always been.
        $device = $this->resolveDevice($request, $validated);

        if (! $device) {
            throw new BusinessRuleException(
                'Choose the handheld this audit was counted on. The export does not name a device, and an audit is '
                .'identified by its shop, its device and its number.'
            );
        }

        $result = $this->audits->import($file, $request->user(), $device);

        return ApiResponse::success(
            new AuditResource($result['audit']),
            $result['duplicate']
                ? sprintf('%s has already been imported. Nothing was changed.', $result['summary']['reference'])
                : sprintf(
                    'Audit %s imported. %s line(s) recorded.',
                    $result['summary']['reference'],
                    number_format($result['summary']['imported'])
                ),
            ['kind' => $kind, 'summary' => $result['summary'], 'duplicate' => $result['duplicate']],
            $result['duplicate'] ? 200 : 201
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validateUpload(Request $request): array
    {
        return $request->validate([
            'file' => ['required', 'file', 'mimes:xls,xlsx', 'max:102400'],
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
        ], [
            'file.mimes' => 'Only Excel files with an .xls or .xlsx extension can be imported.',
            'file.max' => 'The export must not be larger than 100 MB.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveDevice(Request $request, array $validated): ?Device
    {
        if (empty($validated['device_id'])) {
            return null;
        }

        return Device::findOrFail($validated['device_id']);
    }
}
