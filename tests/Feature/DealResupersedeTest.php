<?php

namespace Tests\Feature;

use App\Notifications\BidAcceptedNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\CancelDeal;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\PlaceBidFor;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Админ отменил сделку и через минуту принял снова тому же менеджеру (17.10.2026, Mazda CX-5 Макарова): менеджеру это не
 * отмена. Ошибка молчалива — он видит «Отменена» по идущей сделке и пишет в поддержку.
 */
class DealResupersedeTest extends TestCase
{
    use RefreshDatabase;

    public function test_redone_deal_is_not_cancelled_for_manager(): void
    {
        Storage::fake('private');
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Менеджер', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Т тест']);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1200000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);

        $first = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $manager, 1200000), $admin);
        $this->assertSame(1, $manager->notifications()->where('type', BidAcceptedNotice::class)->count());
        app(CancelDeal::class)($first, $admin);
        $this->assertSame(0, $manager->notifications()->count(), 'отмена стирает «принято» отменённой сделки');

        $offer->fresh()->forceFill(['state' => OfferState::Open])->save();
        $second = app(AcceptBid::class)(app(PlaceBidFor::class)($offer->fresh(), $manager, $admin, 1200000), $admin, 30000);

        $this->actingAs($manager)->get('http://xcar.localhost/deals/'.$first->id)->assertRedirect($second->href());
        $this->actingAs($manager)->get('http://xcar.localhost/deals?view=archive')->assertOk()->assertDontSee($offer->titleWithYear());
    }
}
