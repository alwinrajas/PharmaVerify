<?php

namespace Database\Seeders;

use App\Models\Device;
use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Shop;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class MasterDataSeeder extends Seeder
{
    /**
     * Shops, HHT devices, the pharmacy item master and the current system
     * stock per shop. The same product deliberately appears in more than one
     * shop so that shop-level stock identity is visible in the demo.
     */
    public function run(): void
    {
        $shops = [
            ['PHM001', 'Wellness Pharmacy - Anna Nagar', '112, 2nd Avenue, Anna Nagar', 'Chennai', 'Karthik Subramani', '+91 44 2620 1101', 4],
            ['PHM002', 'MediCare Pharmacy - T. Nagar', '45, Usman Road, T. Nagar', 'Chennai', 'Meena Lakshmi', '+91 44 2434 5502', 3],
            ['PHM003', 'CityHealth Pharmacy - Velachery', '7, Taramani Link Road, Velachery', 'Chennai', 'Suresh Kumar', '+91 44 2255 7703', 2],
        ];

        foreach ($shops as [$code, $name, $address, $city, $contact, $phone, $deviceCount]) {
            $shop = Shop::updateOrCreate(
                ['shop_code' => $code],
                [
                    'shop_name' => $name,
                    'address' => $address,
                    'city' => $city,
                    'contact_person' => $contact,
                    'contact_number' => $phone,
                    'status' => 'active',
                ]
            );

            for ($i = 1; $i <= $deviceCount; $i++) {
                $deviceCode = sprintf('HHT-%02d', $i);

                Device::updateOrCreate(
                    ['shop_id' => $shop->id, 'device_code' => $deviceCode],
                    [
                        'description' => 'Zebra TC21 Hand Held Terminal '.$i,
                        'serial_number' => sprintf('%s-SN-%04d', $code, 1000 + $i),
                        'status' => 'active',
                    ]
                );
            }
        }

        foreach ($this->items() as $definition) {
            Item::updateOrCreate(
                ['product_code' => $definition['product_code']],
                $definition + ['status' => 'active']
            );
        }

        $this->seedStock();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function items(): array
    {
        return [
            ['product_code' => 'MED-1001', 'barcode' => '8901234500011', 'description' => 'Paracetamol 500mg Tablet', 'generic_name' => 'Paracetamol', 'manufacturer' => 'Cipla Ltd', 'uom' => 'STRIP', 'price' => 32.50],
            ['product_code' => 'MED-1002', 'barcode' => '8901234500028', 'description' => 'Amoxicillin 250mg Capsule', 'generic_name' => 'Amoxicillin', 'manufacturer' => 'Sun Pharmaceutical', 'uom' => 'STRIP', 'price' => 88.00],
            ['product_code' => 'MED-1003', 'barcode' => '8901234500035', 'description' => 'Azithromycin 500mg Tablet', 'generic_name' => 'Azithromycin', 'manufacturer' => 'Alkem Laboratories', 'uom' => 'STRIP', 'price' => 121.75],
            ['product_code' => 'MED-1004', 'barcode' => '8901234500042', 'description' => 'Metformin 500mg Tablet', 'generic_name' => 'Metformin HCl', 'manufacturer' => 'USV Private Ltd', 'uom' => 'STRIP', 'price' => 46.20],
            ['product_code' => 'MED-1005', 'barcode' => '8901234500059', 'description' => 'Atorvastatin 10mg Tablet', 'generic_name' => 'Atorvastatin', 'manufacturer' => 'Zydus Healthcare', 'uom' => 'STRIP', 'price' => 94.30],
            ['product_code' => 'MED-1006', 'barcode' => '8901234500066', 'description' => 'Amlodipine 5mg Tablet', 'generic_name' => 'Amlodipine Besylate', 'manufacturer' => 'Torrent Pharma', 'uom' => 'STRIP', 'price' => 38.90],
            ['product_code' => 'MED-1007', 'barcode' => '8901234500073', 'description' => 'Pantoprazole 40mg Tablet', 'generic_name' => 'Pantoprazole', 'manufacturer' => 'Dr Reddys Laboratories', 'uom' => 'STRIP', 'price' => 105.60],
            ['product_code' => 'MED-1008', 'barcode' => '8901234500080', 'description' => 'Cetirizine 10mg Tablet', 'generic_name' => 'Cetirizine HCl', 'manufacturer' => 'Mankind Pharma', 'uom' => 'STRIP', 'price' => 27.40],
            ['product_code' => 'MED-1009', 'barcode' => '8901234500097', 'description' => 'Montelukast 10mg Tablet', 'generic_name' => 'Montelukast Sodium', 'manufacturer' => 'Cipla Ltd', 'uom' => 'STRIP', 'price' => 148.00],
            ['product_code' => 'MED-1010', 'barcode' => '8901234500103', 'description' => 'Insulin Glargine 100IU/ml Cartridge', 'generic_name' => 'Insulin Glargine', 'manufacturer' => 'Biocon Ltd', 'uom' => 'EA', 'price' => 812.00],
            ['product_code' => 'MED-1011', 'barcode' => '8901234500110', 'description' => 'Salbutamol Inhaler 100mcg', 'generic_name' => 'Salbutamol', 'manufacturer' => 'Cipla Ltd', 'uom' => 'EA', 'price' => 176.50],
            ['product_code' => 'MED-1012', 'barcode' => '8901234500127', 'description' => 'Ibuprofen 400mg Tablet', 'generic_name' => 'Ibuprofen', 'manufacturer' => 'Abbott India', 'uom' => 'STRIP', 'price' => 41.80],
            ['product_code' => 'MED-1013', 'barcode' => '8901234500134', 'description' => 'Omeprazole 20mg Capsule', 'generic_name' => 'Omeprazole', 'manufacturer' => 'Sun Pharmaceutical', 'uom' => 'STRIP', 'price' => 62.10],
            ['product_code' => 'MED-1014', 'barcode' => '8901234500141', 'description' => 'Losartan 50mg Tablet', 'generic_name' => 'Losartan Potassium', 'manufacturer' => 'Torrent Pharma', 'uom' => 'STRIP', 'price' => 73.25],
            ['product_code' => 'MED-1015', 'barcode' => '8901234500158', 'description' => 'Vitamin D3 60000IU Sachet', 'generic_name' => 'Cholecalciferol', 'manufacturer' => 'Alkem Laboratories', 'uom' => 'EA', 'price' => 58.00],
            ['product_code' => 'MED-1016', 'barcode' => '8901234500165', 'description' => 'Cough Syrup 100ml Bottle', 'generic_name' => 'Dextromethorphan', 'manufacturer' => 'Glenmark Pharma', 'uom' => 'BTL', 'price' => 118.40],
            ['product_code' => 'MED-1017', 'barcode' => '8901234500172', 'description' => 'ORS Powder 21.8g Sachet', 'generic_name' => 'Oral Rehydration Salts', 'manufacturer' => 'FDC Ltd', 'uom' => 'EA', 'price' => 22.00],
            ['product_code' => 'MED-1018', 'barcode' => '8901234500189', 'description' => 'Povidone Iodine Ointment 15g', 'generic_name' => 'Povidone Iodine', 'manufacturer' => 'Win-Medicare', 'uom' => 'EA', 'price' => 67.90],
            ['product_code' => 'SUR-2001', 'barcode' => '8909876500015', 'description' => 'Digital Thermometer', 'generic_name' => null, 'manufacturer' => 'Omron Healthcare', 'uom' => 'EA', 'price' => 285.00],
            ['product_code' => 'SUR-2002', 'barcode' => '8909876500022', 'description' => 'Surgical Face Mask 3 Ply (Box of 50)', 'generic_name' => null, 'manufacturer' => 'Romsons', 'uom' => 'BOX', 'price' => 210.00],
            ['product_code' => 'SUR-2003', 'barcode' => '8909876500039', 'description' => 'Crepe Bandage 10cm x 4m', 'generic_name' => null, 'manufacturer' => 'Datt Mediproducts', 'uom' => 'EA', 'price' => 96.50],
            ['product_code' => 'SUR-2004', 'barcode' => '8909876500046', 'description' => 'Blood Glucose Test Strips (Pack of 50)', 'generic_name' => null, 'manufacturer' => 'Accu-Chek', 'uom' => 'BOX', 'price' => 940.00],
        ];
    }

    /**
     * Current system stock. Batch and expiry vary per shop so that the
     * Shop + Product + Batch stock identity is exercised.
     */
    private function seedStock(): void
    {
        $plan = [
            'PHM001' => ['MED-1001', 'MED-1002', 'MED-1003', 'MED-1004', 'MED-1005', 'MED-1006', 'MED-1007', 'MED-1008', 'MED-1009', 'MED-1010', 'MED-1011', 'MED-1012', 'SUR-2001', 'SUR-2002', 'SUR-2004'],
            'PHM002' => ['MED-1001', 'MED-1002', 'MED-1004', 'MED-1007', 'MED-1012', 'MED-1013', 'MED-1014', 'MED-1015', 'MED-1016', 'MED-1017', 'SUR-2002', 'SUR-2003'],
            'PHM003' => ['MED-1001', 'MED-1005', 'MED-1008', 'MED-1013', 'MED-1015', 'MED-1016', 'MED-1017', 'MED-1018', 'SUR-2001', 'SUR-2003'],
        ];

        $shelves = ['A-01', 'A-02', 'B-01', 'B-02', 'C-01', 'C-02', 'COLD-01'];

        foreach ($plan as $shopCode => $productCodes) {
            $shop = Shop::where('shop_code', $shopCode)->firstOrFail();
            $index = 0;

            foreach ($productCodes as $productCode) {
                $item = Item::where('product_code', $productCode)->firstOrFail();
                $index++;

                $batch = sprintf('B%s%03d', substr($shopCode, -2), $index);
                $qty = match (true) {
                    str_starts_with($productCode, 'SUR') => 20 + ($index * 7),
                    default => 60 + ($index * 13),
                };

                ItemStock::updateOrCreate(
                    [
                        'shop_id' => $shop->id,
                        'product_code' => $item->product_code,
                        'batch' => $batch,
                    ],
                    [
                        'item_id' => $item->id,
                        'barcode' => $item->barcode,
                        'description' => $item->description,
                        'system_qty' => $qty,
                        'uom' => $item->uom,
                        'price' => $item->price,
                        'expiry_date' => Carbon::create(2027, 1, 1)->addMonths($index * 2)->endOfMonth()->toDateString(),
                        'shelf_location' => $shelves[$index % count($shelves)],
                        'verification_status' => 'not_verified',
                    ]
                );
            }
        }
    }
}
