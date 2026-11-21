<?php

namespace Tests\Feature;

use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\DealScheme;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 22.11.2026, BMW X5 2611184295 Росгосстраха: у поставщика не было маршрута продажи, и принятая сделка встала без этапа.
 * Теперь при входе в сделку у такого поставщика заводится маршрут по умолчанию — копия Т-Банка, сделка встаёт на его этап.
 */
class VendorDefaultRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_deal_of_vendor_without_route_gets_default_route_stage(): void
    {
        $admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Армен', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Росгосстрах тест']);
        $this->assertNull($vendor->workflow(Track::Sale));
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1000000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->firstOrCreate(['user_id' => $manager->id], ['opens_at' => now()]);

        app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $manager, 970000), $admin, scheme: DealScheme::resolve($offer->fresh(), null));

        $offer = $offer->fresh();
        $this->assertNotNull($offer->deal);
        $this->assertNotNull($offer->position(Track::Sale), 'сделка без этапа');
        $this->assertTrue($vendor->fresh()->workflow(Track::Sale)->stages()->exists());
    }
}
