<?php

namespace Tests\Feature;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Http\Admin\OfferController;
use App\Media\Jobs\StampPhotos;
use App\Offers\Actions\SendToAuction;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * «На аукцион» (13.10.2026): «Отправить» снимает с публикации и ставит во вкладку; «По истечению срока» отправляет,
 * только если приём закончился без подтверждений, иначе план снимается; отмена и уход черновика дальше гасят отметки.
 */
class AuctionTest extends TestCase
{
    use RefreshDatabase;

    private function open(): Offer
    {
        Notification::fake();
        Queue::fake([StampPhotos::class]);
        $brand = Brand::create(['slug' => 'kia', 'name' => 'Kia']);
        $model = CarModel::create(['brand_id' => $brand->id, 'slug' => 'rio', 'name' => 'Rio']);
        $offer = Offer::create(['brand_id' => $brand->id, 'model_id' => $model->id, 'floor_price' => 500000, 'asking_price' => 700000]);
        $offer->forceFill(['state' => OfferState::Open, 'published_at' => now(), 'bids_close_at' => now()->addDay()])->save();

        return $offer->fresh();
    }

    private function admin(): User
    {
        return User::create(['name' => 'Админов Андрей', 'phone' => '7900'.random_int(1000000, 9999999), 'roles' => [Role::Admin], 'approved_at' => now()]);
    }

    private function inTab(string $preset, Offer $offer): bool
    {
        return OfferController::scopeFor($preset, Offer::whereKey($offer->id))->exists();
    }

    public function test_send_now_unpublishes_into_auction_tab(): void
    {
        $offer = app(SendToAuction::class)->now($this->open(), $this->admin());

        $this->assertSame(OfferState::Draft, $offer->state);
        $this->assertTrue($this->inTab('auction', $offer));
        $this->assertFalse($this->inTab('priced', $offer));
        $this->assertSame('На аукцион', $offer->crmTab());
    }

    public function test_planned_goes_to_auction_only_without_bids(): void
    {
        $admin = $this->admin();
        $send = app(SendToAuction::class);
        $quiet = $send->plan($this->open(), $admin);
        $this->assertTrue($quiet->auctionPlanned());
        $this->assertSame([['now', 'Отправить на аукцион', 'Снять с публикации и отправить на аукцион?'], ['cancel', 'Отменить отправку на аукцион', null]], $quiet->auctionActions());

        $busy = Offer::create(['floor_price' => 400000, 'asking_price' => 600000]);
        $busy->forceFill(['state' => OfferState::Open, 'published_at' => now(), 'bids_close_at' => now()->addDay()])->save();
        $send->plan($busy->fresh(), $admin);
        $manager = User::create(['name' => 'Иванов Иван', 'phone' => '79001112233', 'roles' => [Role::Manager], 'approved_at' => now()]);
        Bid::create(['offer_id' => $busy->id, 'user_id' => $manager->id, 'amount' => 650000, 'state' => BidState::Active]);

        $this->travel(2)->days();
        $this->assertSame(2, $send->due());

        $quiet->refresh();
        $busy->refresh();
        $this->assertTrue($quiet->onAuction());
        $this->assertSame(OfferState::Open, $busy->state);
        $this->assertNull($busy->auction_planned_at);
    }

    public function test_cancel_and_leaving_draft_clear_marks(): void
    {
        $admin = $this->admin();
        $send = app(SendToAuction::class);
        $offer = $send->cancel($send->plan($this->open(), $admin), $admin);
        $this->assertFalse($offer->auctionPlanned());

        $offer = $send->now($offer, $admin);
        // Черновик ушёл дальше (в архив; так же публикация) — с аукциона снят.
        app(\App\Offers\Actions\ChangeOfferState::class)($offer, OfferState::Archived, $admin);
        $this->assertNull($offer->fresh()->auction_at);
    }
}
