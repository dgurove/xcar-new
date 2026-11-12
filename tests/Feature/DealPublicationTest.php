<?php

namespace Tests\Feature;

use App\Notifications\DealOpenedNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\Actions\NotifyViewers;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\PublishDeal;
use App\Offers\Actions\WithdrawBid;
use App\Offers\AudienceRules;
use App\Offers\BidState;
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
 * Публикация машины в сделке для других менеджеров (`PublishDeal`, 12.11.2026): по умолчанию её не видит никто, кроме
 * участников; кому — те же правила «кто → когда», что волны предложения («Выбранным», «Всем, кроме», волны), видят её
 * обычным предложением, подтверждают резервом, «Новое предложение» — с волной. Уход из сделки публикацию стирает.
 */
class DealPublicationTest extends TestCase
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

    public function test_publication_to_chosen_full_cycle(): void
    {
        $offer = $this->setupCase();

        // «Выбранным: Армен» — как из шторки «Опубликовать для других менеджеров».
        app(PublishDeal::class)($offer->fresh(), [['type' => 'user', 'id' => $this->armen->id, 'delay' => 0], ['type' => 'rest', 'id' => null, 'delay' => null]], now()->addDays(3), $this->admin);
        $offer = $offer->fresh();

        $this->assertTrue($offer->seesDeal($this->armen));
        $this->assertTrue($offer->canBidInDeal($this->armen));
        $this->assertTrue(Offer::visibleTo($this->armen)->whereKey($offer->id)->exists());
        $this->assertTrue(Offer::siteList($this->armen)->whereKey($offer->id)->exists());
        $this->assertSame('Только '.$this->armen->shortName(), AudienceRules::summary(AudienceRules::forDeal($offer)));
        // Чужому и менеджеру сделки — нет.
        $this->assertFalse($offer->seesDeal($this->stranger));
        $this->assertFalse(Offer::siteList($this->stranger)->whereKey($offer->id)->exists());
        $this->assertFalse($offer->seesDeal($this->denis));
        Notification::assertSentTo($this->armen, DealOpenedNotice::class);
        Notification::assertNotSentTo($this->stranger, DealOpenedNotice::class);
        Notification::assertNotSentTo($this->denis, DealOpenedNotice::class);

        // Армен подтверждает в сделке — резервом, сделка Дениса жива.
        $bid = app(PlaceBid::class)($offer, $this->armen, 1040000);
        $this->assertSame(BidState::Active, $bid->state);
        $this->assertSame($this->denis->id, $offer->fresh()->deal->buyer_id);

        // Резерв можно отозвать, пока сделка идёт.
        app(WithdrawBid::class)($bid->fresh(), $this->armen);
        $this->assertSame(BidState::Withdrawn, $bid->fresh()->state);

        // Уход из сделки стирает публикацию: Армен снова не видит.
        app(ChangeOfferState::class)($offer->fresh(), OfferState::Cancelled, $this->admin);
        $offer = $offer->fresh();
        $this->assertSame(OfferState::Cancelled, $offer->state);
        $this->assertNull($offer->deal_published_at);
        $this->assertNull($offer->deal_audience_rules);
        $this->assertFalse($offer->seesDeal($this->armen));
    }

    /**
     * «Всем, кроме» группы (12.11.2026, владелец: «нельзя показывать всем КРОМЕ каких-то менеджеров»): исключённый не видит,
     * даже если в другой группе ему «сразу»; остальные видят; поправили — выпавший теряет машину, новый получает её.
     */
    public function test_everyone_except_group_and_edit(): void
    {
        $offer = $this->setupCase();
        $hidden = UserGroup::create(['name' => 'Скрытые', 'kind' => UserGroup::MANAGERS]);
        $fast = UserGroup::create(['name' => 'Быстрые', 'kind' => UserGroup::MANAGERS]);
        $hidden->members()->attach($this->armen->id);
        $fast->members()->attach($this->armen->id);

        $rules = [['type' => 'group', 'id' => $fast->id, 'delay' => 0], ['type' => 'group', 'id' => $hidden->id, 'delay' => null], ['type' => 'rest', 'id' => null, 'delay' => 0]];
        app(PublishDeal::class)($offer->fresh(), $rules, now()->addDays(3), $this->admin);
        $offer = $offer->fresh();
        $this->assertFalse($offer->seesDeal($this->armen));
        $this->assertTrue($offer->seesDeal($this->stranger));
        $this->assertSame('Быстрые сразу, остальные сразу, кроме Скрытые', AudienceRules::summary(AudienceRules::forDeal($offer)));

        // Поправили: «Всем, кроме» — чужака; Армен теперь видит, чужак — нет.
        app(PublishDeal::class)($offer, [['type' => 'user', 'id' => $this->stranger->id, 'delay' => null], ['type' => 'rest', 'id' => null, 'delay' => 0]], now()->addDays(3), $this->admin);
        $offer = $offer->fresh();
        $this->assertTrue($offer->seesDeal($this->armen));
        $this->assertFalse($offer->seesDeal($this->stranger));
        $this->assertSame('Всем, кроме '.$this->stranger->shortName(), AudienceRules::summary(AudienceRules::forDeal($offer)));

        // Волна: остальным через 2 часа — Армен видит, когда она наступит, и тогда же получает «Новое предложение».
        app(PublishDeal::class)($offer, [['type' => 'user', 'id' => $this->stranger->id, 'delay' => 0], ['type' => 'rest', 'id' => null, 'delay' => 120]], now()->addDays(3), $this->admin);
        $this->assertFalse($offer->fresh()->seesDeal($this->armen));
        $this->travel(121)->minutes();
        app(NotifyViewers::class)->due();
        $this->assertTrue($offer->fresh()->seesDeal($this->armen));
        Notification::assertSentTo($this->armen, DealOpenedNotice::class);

        // Сняли — не видит никто.
        app(PublishDeal::class)->stop($offer->fresh(), $this->admin);
        $this->assertFalse($offer->fresh()->seesDeal($this->armen));
        $this->assertFalse(Offer::siteList($this->stranger)->whereKey($offer->id)->exists());
    }
}
