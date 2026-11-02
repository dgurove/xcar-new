<?php

namespace Tests\Feature;

use App\Garage\GaragePayer;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\BidKind;
use App\Offers\Deal;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Support\Nav;
use App\Support\Surface;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Preset;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Гаражные сделки в «Сделках» сайта (02.11.2026, владелец): строкой с меткой «Гараж», ход — только просьбой; бейдж «Сделок»
 * считает их, значок приложения — без двойного счёта с «Гаражом».
 */
class GarageInDealsTest extends TestCase
{
    use RefreshDatabase;

    public function test_garage_deal_is_in_deals_and_counted_once(): void
    {
        config(['xcar.mercure.hub' => '']);
        Storage::fake('private');
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Менеджер', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1200000, 'garage_allowed' => true, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $bid = app(PlaceBid::class)($offer->fresh(), $manager, null, null, BidKind::Garage);
        $deal = app(AcceptBid::class)($bid, $admin, payer: GaragePayer::Manager);

        $this->actingAs($manager)->get('http://xcar.localhost/deals')->assertOk()
            ->assertSee($offer->fresh()->titleWithYear())->assertSee('Гараж');

        // Ход у гаражной — только просьбой: без просьбы ни строки «Ваш ход», ни бейджа.
        $this->assertNull(Deal::with('openRequirement')->find($deal->id)->managerTask());
        $this->assertSame(['deals' => 0, 'garage' => 0], Nav::asks($manager));

        Requirement::create(['offer_id' => $offer->id, 'deal_id' => $deal->id, 'stage_id' => $offer->fresh()->position()?->stage_id,
            'user_id' => $manager->id, 'title' => 'Заберите автомобиль', 'due_at' => now()->addDay()]);
        Cache::flush();
        $this->app->forgetScopedInstances();
        $deal = Deal::with('openRequirement')->find($deal->id);
        $this->assertNotNull($deal->managerTask());
        $asks = Nav::asks($manager);
        $this->assertSame(1, $asks['garage']);
        $this->assertSame($asks['deals'], $asks['garage']);
        // Значок приложения: гаражная просьба — один раз.
        $this->assertSame($manager->unreadChats(platform: false) + $asks['deals'], $manager->badgeCount(Surface::Site));
    }
}
