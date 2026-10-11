<?php

namespace Tests\Feature;

use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Vendors\DealFormat;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Каркаде (оптом, выкупаем мы; 11.10.2026): забирать машину — только после того, как поставщик согласовал заявку.
 * До того менеджер ждёт: ни «Вам поручен вывоз», ни «забрать», ни бейджа.
 */
class WholesalePickupHoldTest extends TestCase
{
    use RefreshDatabase;

    public function test_wholesale_deal_waits_for_supplier_agreement(): void
    {
        $vendor = Vendor::create(['name' => 'Каркаде тест', 'deal_format' => DealFormat::Wholesale]);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 500000]);
        $deal = Deal::create(['offer_id' => $offer->id, 'state' => DealState::Active, 'cost' => 500000]);
        $deal->load('offer.vendor');

        $this->assertFalse($deal->mayPickUp());
        $this->assertSame('ждём поставщика', $deal->pickupHold());

        $deal->update(['agreed_at' => now()]);
        $this->assertTrue($deal->fresh()->load('offer.vendor')->mayPickUp());
    }

    public function test_other_vendors_pick_up_any_time(): void
    {
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => DealFormat::Commission]);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 500000]);
        $deal = Deal::create(['offer_id' => $offer->id, 'state' => DealState::Active, 'cost' => 500000])->load('offer.vendor');

        $this->assertTrue($deal->mayPickUp());
    }
}
