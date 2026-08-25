<?php

namespace Database\Seeders;

use App\Models\Device;
use App\Models\ItemStock;
use App\Models\Shop;
use App\Services\HhtSubmissionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Realistic completed HHT counts, submitted through the same service the real
 * devices use.
 *
 * The audit numbering mirrors the way the shops actually work: within a shop
 * each device advances its own audit sequence, so the same audit number shows
 * up on several devices and devices drift out of step with each other.
 */
class DemoAuditSeeder extends Seeder
{
    public function run(HhtSubmissionService $service): void
    {
        $plan = [
            // shop, device, audit number, days ago, counted by
            ['PHM001', 'HHT-01', 1, 34, 'Karthik Subramani'],
            ['PHM001', 'HHT-02', 1, 34, 'Vignesh Anand'],
            ['PHM001', 'HHT-03', 1, 34, 'Priya Nair'],
            ['PHM001', 'HHT-04', 1, 34, 'Rahul Menon'],
            ['PHM001', 'HHT-01', 2, 17, 'Karthik Subramani'],
            ['PHM001', 'HHT-02', 2, 17, 'Vignesh Anand'],
            ['PHM001', 'HHT-03', 2, 3, 'Priya Nair'],
            ['PHM001', 'HHT-04', 2, 3, 'Rahul Menon'],
            ['PHM001', 'HHT-01', 3, 2, 'Karthik Subramani'],
            ['PHM001', 'HHT-02', 3, 2, 'Vignesh Anand'],
            ['PHM002', 'HHT-01', 1, 21, 'Meena Lakshmi'],
            ['PHM002', 'HHT-02', 1, 21, 'Sanjay Iyer'],
            ['PHM002', 'HHT-01', 2, 4, 'Meena Lakshmi'],
            ['PHM003', 'HHT-01', 1, 6, 'Suresh Kumar'],
        ];

        foreach ($plan as $index => [$shopCode, $deviceCode, $auditNumber, $daysAgo, $countedBy]) {
            $shop = Shop::where('shop_code', $shopCode)->first();
            $device = $shop ? Device::where('shop_id', $shop->id)->where('device_code', $deviceCode)->first() : null;

            if (! $shop || ! $device) {
                continue;
            }

            $service->receive([
                'submission_uid' => sprintf('SUB-%s-%s-A%d', $shopCode, $deviceCode, $auditNumber),
                'shop_code' => $shopCode,
                'device_code' => $deviceCode,
                'audit_number' => $auditNumber,
                'audit_date' => Carbon::now()->subDays($daysAgo)->toDateString(),
                'hht_user' => $countedBy,
                'app_version' => '1.4.2',
                'items' => $this->countedItems($shop->id, $index, $auditNumber),
            ]);
        }
    }

    /**
     * Builds a counted item list for one device. Devices in the same shop count
     * different sections of the shelf, so each takes its own slice of the stock.
     *
     * @return array<int, array<string, mixed>>
     */
    private function countedItems(int $shopId, int $index, int $auditNumber): array
    {
        $stocks = ItemStock::where('shop_id', $shopId)->orderBy('id')->get();

        if ($stocks->isEmpty()) {
            return [];
        }

        $slice = $stocks->slice(($index * 3) % max(1, $stocks->count() - 4))->take(8)->values();

        if ($slice->isEmpty()) {
            $slice = $stocks->take(8)->values();
        }

        $items = [];

        foreach ($slice as $position => $stock) {
            $systemQty = (float) $stock->system_qty;

            // A realistic spread: most lines agree, a few are short, a couple
            // are over, which gives the demo negative, positive and zero variance.
            $physicalQty = match (($position + $auditNumber) % 5) {
                0 => $systemQty - 5,
                1 => $systemQty,
                2 => $systemQty + 3,
                3 => $systemQty,
                default => $systemQty - 2,
            };

            $items[] = [
                'barcode' => $stock->barcode,
                'product_code' => $stock->product_code,
                'physical_quantity' => max(0, $physicalQty),
                'batch' => $stock->batch,
                'expiry' => $stock->expiry_date?->toDateString(),
                'uom' => $stock->uom,
                'shelf_location' => $stock->shelf_location,
            ];
        }

        // The most recent counts include a product found on the shelf that the
        // shop's stock file does not carry — the case Stock Take exists for.
        if ($auditNumber >= 2 && $index % 3 === 0) {
            $items[] = [
                'barcode' => '8901234599999',
                'product_code' => 'MED-1099',
                'description' => 'Rabeprazole 20mg Tablet',
                'physical_quantity' => 24,
                'batch' => 'BX2210',
                'expiry' => Carbon::create(2027, 9, 30)->toDateString(),
                'uom' => 'STRIP',
                'shelf_location' => 'B-02',
            ];
        }

        return $items;
    }
}
