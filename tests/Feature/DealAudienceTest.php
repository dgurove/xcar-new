<?php

namespace Tests\Feature;

use App\Notifications\DealOpenedNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\Actions\NotifyDealAudience;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Actions\WithdrawBid;
use App\Offers\BidState;
use App\Offers\DealAudience;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Users\UserGroup;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * «Кому показывать в сделке»: белый список менеджеров и групп. По умолчанию машину в сделке
 * не видит никто, кроме участников; выбранные видят её в предложениях с меткой «Идёт сделка»,
 * подтверждают резервом, новым уходит уведомление. Уход из сделки список стирает.
 */
class DealAudienceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $denis;

    private User $armen;

    private User $stranger;

    private function setupCase(): Offer
    {
        Storage::fake('private');
        Notification::fake();
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->denis = User::create(['name' => 'Денисов Денис', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->armen = User::create(['name' => 'Арменов Армен', 'phone' => '79000000003', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->stranger = User::create(['name' => 'Чужов Чужак', 'phone' => '79000000004', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 944000, 'asking_price' => 1050000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->denis->id, 'opens_at' => now()]);
        app(AcceptBid::class)(app(PlaceBid::class)($offer, $this->denis, 1050000), $this->admin);

        return $offer->fresh();
    }

    public function test_deal_hidden_by_default_and_opened_to_manager(): void
    {
        $offer = $this->setupCase();
        $this->assertSame(OfferState::Sold, $offer->state);

        // По умолчанию Армен сделку не видит и подтвердить не может.
        $this->assertFalse($offer->seesDeal($this->armen));
        $this->assertFalse(Offer::visibleTo($this->armen)->whereKey($offer->id)->exists());
        $this->assertFalse(Offer::siteList($this->armen)->whereKey($offer->id)->exists());
        $this->expectException(ValidationException::class);
        app(PlaceBid::class)($offer, $this->armen, 1050000);
    }

    public function test_deal_audience_full_cycle(): void
    {
        $offer = $this->setupCase();

        // Открыли Армену через UpdateOffer — как из редактора.
        app(UpdateOffer::class)($offer->fresh(), ['deal_audience_users' => [$this->armen->id], 'deal_audience_groups' => []], $this->admin);
        $offer = $offer->fresh();

        $this->assertTrue($offer->seesDeal($this->armen));
        $this->assertTrue($offer->canBidInDeal($this->armen));
        $this->assertTrue(Offer::visibleTo($this->armen)->whereKey($offer->id)->exists());
        $this->assertTrue(Offer::siteList($this->armen)->whereKey($offer->id)->exists());
        $this->assertSame($this->armen->shortName(), DealAudience::summary($offer));
        // Чужому по-прежнему закрыто.
        $this->assertFalse($offer->seesDeal($this->stranger));
        $this->assertFalse(Offer::siteList($this->stranger)->whereKey($offer->id)->exists());
        Notification::assertSentTo($this->armen, DealOpenedNotice::class);
        Notification::assertNotSentTo($this->stranger, DealOpenedNotice::class);

        // Армен подтверждает в сделке — резервом, сделка Дениса жива.
        $bid = app(PlaceBid::class)($offer, $this->armen, 1040000);
        $this->assertSame(BidState::Active, $bid->state);
        $this->assertSame($this->denis->id, $offer->fresh()->deal->buyer_id);

        // Резерв можно отозвать, пока сделка идёт.
        app(WithdrawBid::class)($bid->fresh(), $this->armen);
        $this->assertSame(BidState::Withdrawn, $bid->fresh()->state);

        // Уход из сделки стирает список: Армен снова не видит.
        app(ChangeOfferState::class)($offer->fresh(), OfferState::Cancelled, $this->admin);
        $offer = $offer->fresh();
        $this->assertSame(OfferState::Cancelled, $offer->state);
        $this->assertSame(0, $offer->dealAudience()->count());
        $this->assertFalse($offer->seesDeal($this->armen));
    }

    public function test_deal_audience_via_group(): void
    {
        $offer = $this->setupCase();
        $group = UserGroup::create(['name' => 'Тестовая', 'kind' => UserGroup::MANAGERS]);
        $group->members()->attach($this->armen->id);

        $sync = DealAudience::sync($offer, [], [$group->id]);
        $this->assertTrue($sync['changed']);
        $this->assertSame([$this->armen->id], $sync['added']);

        app(NotifyDealAudience::class)->added($offer->fresh(), $sync['added']);
        $this->assertTrue($offer->fresh()->seesDeal($this->armen));
        $this->assertTrue(Offer::siteList($this->armen)->whereKey($offer->id)->exists());
        $this->assertFalse($offer->fresh()->seesDeal($this->stranger));
        Notification::assertSentTo($this->armen, DealOpenedNotice::class);
    }
}
