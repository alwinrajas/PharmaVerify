<?php

namespace Tests\Feature;

use App\Models\ItemStock;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The GTIN is the identifier the handheld scans.
 *
 * Scanning it must find the product and, where the shop holds it in a single
 * batch, bring the batch and expiry with it so nobody has to key them in. The
 * 7-digit internal ITEMBARCODE is a different identifier system and must never
 * take the GTIN's place as the primary match.
 */
class GtinLookupTest extends TestCase
{
    use RefreshDatabase;

    private const GTIN = '08840149636445';

    private const INTERNAL_BARCODE = '4500011';

    public function test_scanning_a_gtin_finds_the_item_with_its_batch_and_expiry(): void
    {
        $user = $this->userWithRole(Roles::ADMINISTRATOR);
        $shop = $this->makeShop();
        $item = $this->makeItem();

        $this->stockLine($shop->id, $item->id, [
            'gtin' => self::GTIN,
            'batch' => 'GM1954',
            'expiry_date' => '2027-04-30',
            'system_qty' => 42,
        ]);

        $response = $this->actingAs($user)->getJson(sprintf(
            '/api/item-stocks/lookup?code=%s&shop_id=%d',
            self::GTIN,
            $shop->id
        ));

        $response->assertOk();

        $this->assertSame('gtin', $response->json('meta.matched_on'));
        $this->assertSame(1, $response->json('meta.match_count'));
        $this->assertFalse($response->json('meta.ambiguous'));

        // The batch and expiry come back with the scan — nothing to key in.
        $this->assertSame('MED-1001', $response->json('data.0.product_code'));
        $this->assertSame('GM1954', $response->json('data.0.batch'));
        $this->assertSame('2027-04-30', $response->json('data.0.expiry_date'));
        $this->assertEquals(42, $response->json('data.0.system_qty'));
    }

    /**
     * Two products, and the code scanned is one product's GTIN and another's
     * internal barcode. The GTIN must win.
     */
    public function test_a_gtin_match_takes_precedence_over_the_seven_digit_barcode(): void
    {
        $user = $this->userWithRole();
        $shop = $this->makeShop();
        $wanted = $this->makeItem('MED-2001', '1111111');
        $other = $this->makeItem('MED-2002', '2222222');

        $this->stockLine($shop->id, $wanted->id, [
            'product_code' => 'MED-2001',
            'gtin' => '5555555',
            'barcode' => '1111111',
            'batch' => 'B-RIGHT',
        ]);

        $this->stockLine($shop->id, $other->id, [
            'product_code' => 'MED-2002',
            'gtin' => '9999999',
            'barcode' => '5555555',
            'batch' => 'B-WRONG',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/item-stocks/lookup?code=5555555&shop_id='.$shop->id)
            ->assertOk();

        $this->assertSame('gtin', $response->json('meta.matched_on'));
        $this->assertSame(1, $response->json('meta.match_count'));
        $this->assertSame('MED-2001', $response->json('data.0.product_code'));
        $this->assertSame('B-RIGHT', $response->json('data.0.batch'));
    }

    /** The older internal code still resolves, but only once the GTIN has not. */
    public function test_the_internal_barcode_still_resolves_when_no_gtin_matches(): void
    {
        $user = $this->userWithRole();
        $shop = $this->makeShop();
        $item = $this->makeItem();

        $this->stockLine($shop->id, $item->id, [
            'gtin' => self::GTIN,
            'barcode' => self::INTERNAL_BARCODE,
            'batch' => 'GM1954',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/item-stocks/lookup?code='.self::INTERNAL_BARCODE.'&shop_id='.$shop->id)
            ->assertOk();

        $this->assertSame('barcode', $response->json('meta.matched_on'));
        $this->assertSame('GM1954', $response->json('data.0.batch'));
    }

    /**
     * Held in more than one batch, the scan cannot fill in a batch on its own.
     * Every candidate is returned and the ambiguity is stated rather than one
     * being picked by a rule nobody agreed.
     */
    public function test_a_gtin_held_in_several_batches_returns_them_all_and_reports_the_ambiguity(): void
    {
        $user = $this->userWithRole();
        $shop = $this->makeShop();
        $item = $this->makeItem();

        $this->stockLine($shop->id, $item->id, [
            'gtin' => self::GTIN, 'batch' => 'B-LATER', 'expiry_date' => '2028-01-31',
        ]);
        $this->stockLine($shop->id, $item->id, [
            'gtin' => self::GTIN, 'batch' => 'B-SOONER', 'expiry_date' => '2027-04-30',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/item-stocks/lookup?code='.self::GTIN.'&shop_id='.$shop->id)
            ->assertOk();

        $this->assertSame(2, $response->json('meta.match_count'));
        $this->assertTrue($response->json('meta.ambiguous'));

        // Earliest expiry first — the stock a shelf is worked from.
        $this->assertSame('B-SOONER', $response->json('data.0.batch'));
    }

    public function test_a_code_no_stock_answers_to_returns_an_empty_result(): void
    {
        $user = $this->userWithRole();
        $shop = $this->makeShop();

        $response = $this->actingAs($user)
            ->getJson('/api/item-stocks/lookup?code=00000000000000&shop_id='.$shop->id)
            ->assertOk();

        $this->assertSame([], $response->json('data'));
        $this->assertNull($response->json('meta.matched_on'));
    }

    /** A scan only ever sees the shop it was made in. */
    public function test_a_lookup_does_not_reach_into_another_shops_stock(): void
    {
        $user = $this->userWithRole();
        $here = $this->makeShop('PHM001');
        $elsewhere = $this->makeShop('PHM002');
        $item = $this->makeItem();

        $this->stockLine($elsewhere->id, $item->id, ['gtin' => self::GTIN, 'batch' => 'B-THERE']);

        $response = $this->actingAs($user)
            ->getJson('/api/item-stocks/lookup?code='.self::GTIN.'&shop_id='.$here->id)
            ->assertOk();

        $this->assertSame([], $response->json('data'));
    }

    /**
     * A count submitted with only the scanned GTIN still lands on the right
     * stock line, and takes its system quantity from it.
     */
    public function test_a_submission_identified_only_by_gtin_matches_the_right_stock_line(): void
    {
        $user = $this->userWithRole();
        $shop = $this->makeShop();
        $this->makeDevice($shop);
        $item = $this->makeItem();

        $this->stockLine($shop->id, $item->id, [
            'gtin' => self::GTIN,
            'barcode' => self::INTERNAL_BARCODE,
            'batch' => 'GM1954',
            'system_qty' => 30,
        ]);

        $this->actingAs($user)->postJson('/api/hht/submissions', [
            'submission_uid' => 'SUB-GTIN-1',
            'shop_code' => 'PHM001',
            'device_code' => 'HHT-01',
            'audit_number' => 1,
            'audit_date' => '2026-08-28',
            'hht_user' => 'Karthik Subramani',
            'items' => [
                ['gtin' => self::GTIN, 'physical_quantity' => 28],
            ],
        ])->assertCreated();

        $line = \App\Models\AuditLine::firstOrFail();

        $this->assertSame('MED-1001', $line->product_code);
        $this->assertSame('GM1954', $line->batch);
        $this->assertEquals(30, $line->system_qty);
        $this->assertEquals(28, $line->physical_qty);
        // System - (Physical + Loose) = 30 - 28 = 2. Positive is short.
        $this->assertEquals(2, $line->variance_qty);
        $this->assertFalse((bool) $line->is_unknown_item);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function stockLine(int $shopId, int $itemId, array $attributes): ItemStock
    {
        return ItemStock::create(array_merge([
            'shop_id' => $shopId,
            'item_id' => $itemId,
            'product_code' => 'MED-1001',
            'barcode' => self::INTERNAL_BARCODE,
            'description' => 'Paracetamol 500mg Tablet',
            'system_qty' => 10,
            'uom' => 'STRIP',
            'price' => 32.5,
            'batch' => 'B001',
            'expiry_date' => '2027-06-30',
            'verification_status' => 'not_verified',
        ], $attributes));
    }
}
