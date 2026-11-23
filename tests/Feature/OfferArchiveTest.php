<?php

namespace Tests\Feature;

use App\Chats\Chat;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\PublishDeal;
use App\Offers\Estimate;
use App\Offers\EstimateState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\OfferViewer;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Архив менеджера (23.11.2026, начальник): ушедшее с витрины за 30 дней видно тому, кому было открыто, — без причины ухода
 * («даже если она у кого-то в сделке, для них она просто архивная»); по нему можно написать. Доступ и утечки — поэтому тест.
 */
class OfferArchiveTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $denis;

    private User $armen;

    private User $olga;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['xcar.mercure.hub' => '']);
        $this->app['db']->extend('pgsql_async', fn () => $this->app['db']->connection('pgsql'));
        $this->admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->denis = User::create(['name' => 'Денис', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->armen = User::create(['name' => 'Армен', 'phone' => '79000000003', 'roles' => [Role::Manager], 'approved_at' => now()]);
        // Ольге машина не открывалась (эксклюзив, волна не наступила) — её архив её не знает.
        $this->olga = User::create(['name' => 'Ольга', 'phone' => '79000000004', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 800000, 'asking_price' => 990000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open, 'published_at' => now()->subHour()])->save();
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        foreach ([$this->denis, $this->armen] as $m) {
            $offer->viewers()->firstOrCreate(['user_id' => $m->id], ['opens_at' => now()->subHour()]);
        }
        app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $this->denis, 970000), $this->admin);
        // Волна Ольги — после ухода (показ «всем сразу» `SyncViewers` открыл бы ей сразу).
        OfferViewer::where('offer_id', $offer->id)->where('user_id', $this->olga->id)->update(['opens_at' => now()->addDays(2)]);
        $this->offer = $offer->fresh();
        $this->assertSame(OfferState::Sold, $this->offer->state);
        $this->assertNotNull($this->offer->sale_ended_at, 'ушло из продажи — метка');
    }

    private function page(User $u): TestResponse
    {
        return $this->actingAs($u)->get('http://xcar.localhost/offers/'.$this->offer->number);
    }

    public function test_given_to_other_is_just_archived(): void
    {
        $this->assertTrue($this->offer->isArchivedFor($this->armen));
        $this->actingAs($this->armen)->get('http://xcar.localhost/offers/archive')->assertOk()->assertSee('/offers/'.$this->offer->number, false);
        $this->page($this->armen)->assertOk()->assertSeeText('в архиве')->assertSeeText('Написать')
            ->assertDontSeeText('сделк')->assertDontSeeText('риём')->assertDontSeeText('резерв')->assertDontSeeText('Подтвердить')->assertDontSeeText('970');

        // Своя сделка — не архив: она в его «Сделках».
        $this->assertFalse($this->offer->isArchivedFor($this->denis));
        // Не открывалась — ни архива, ни страницы.
        $this->assertFalse($this->offer->isArchivedFor($this->olga));
        $this->page($this->olga)->assertNotFound();
        // Админу — весь архив, а страница ТС у него обычная.
        $this->actingAs($this->admin)->get('http://xcar.localhost/offers/archive')->assertOk()->assertSee('/offers/'.$this->offer->number, false);
        $this->page($this->admin)->assertOk()->assertDontSeeText('в архиве');
        // Покупателю архива нет.
        $buyer = User::create(['name' => 'Пётр', 'phone' => '79000000009', 'roles' => [Role::Buyer], 'manager_id' => $this->armen->id, 'approved_at' => now(), 'buyer_access_at' => now()]);
        $this->actingAs($buyer)->get('http://xcar.localhost/offers/archive')->assertNotFound();
    }

    public function test_thirty_days_and_back_on_sale(): void
    {
        $this->travel(Offer::ARCHIVE_DAYS + 1)->days();
        $this->assertFalse($this->offer->isArchivedFor($this->armen));
        $this->page($this->armen)->assertNotFound();
        $this->travelBack();

        // Сняли с продажи — в архив; вернули — снова на витрине, архива нет.
        $other = Offer::create(['vendor_id' => $this->offer->vendor_id, 'floor_price' => 500000, 'asking_price' => 600000, 'bids_close_at' => now()->addDay()]);
        $other->forceFill(['state' => OfferState::Open, 'published_at' => now()->subHour()])->save();
        $other->viewers()->create(['user_id' => $this->armen->id, 'opens_at' => now()->subHour()]);
        app(ChangeOfferState::class)($other, OfferState::Archived, $this->admin);
        $this->assertTrue($other->fresh()->isArchivedFor($this->armen));
        $other->fresh()->forceFill(['state' => OfferState::Open, 'sale_ended_at' => null])->save();
        $this->assertFalse($other->fresh()->isArchivedFor($this->armen));
        $this->actingAs($this->armen)->get('http://xcar.localhost/offers/'.$other->number)->assertOk()->assertDontSeeText('в архиве');
    }

    public function test_writes_about_archived(): void
    {
        $this->actingAs($this->armen)->get('http://xcar.localhost/chats/offer/'.$this->offer->number)->assertOk();
        $this->actingAs($this->armen)->post('http://xcar.localhost/offers/'.$this->offer->number.'/chat', ['text' => 'Что с ней?'])->assertOk()->assertHeader('X-Chat-Id');
        $this->assertTrue(Chat::where('offer_id', $this->offer->id)->where('user_id', $this->armen->id)->exists());
        $this->actingAs($this->olga)->post('http://xcar.localhost/offers/'.$this->offer->number.'/chat', ['text' => 'А я?'])->assertNotFound();
    }

    public function test_search_finds_archive(): void
    {
        $this->actingAs($this->armen)->get('http://xcar.localhost/search?q='.$this->offer->number)->assertOk()
            ->assertSeeText('Архив')->assertSee('/offers/'.$this->offer->number, false)->assertDontSeeText('в сделке');
        $this->actingAs($this->olga)->get('http://xcar.localhost/search?q='.$this->offer->number)->assertOk()->assertDontSee('/offers/'.$this->offer->number, false);
    }

    /** Открытое предложение с волной Армена — заготовка для путей ухода. */
    private function openOffer(): Offer
    {
        $o = Offer::create(['vendor_id' => $this->offer->vendor_id, 'floor_price' => 500000, 'asking_price' => 600000, 'bids_close_at' => now()->addDay()]);
        $o->forceFill(['state' => OfferState::Open, 'published_at' => now()->subHour()])->save();
        $o->viewers()->create(['user_id' => $this->armen->id, 'opens_at' => now()->subHour()]);

        return $o->fresh();
    }

    public function test_back_to_draft_and_closed_intake(): void
    {
        // Сняли в черновик (окна галереи нет — `gallery_until` пуст): в архиве, страница архивная.
        $draft = $this->openOffer();
        app(ChangeOfferState::class)($draft, OfferState::Draft, $this->admin);
        $this->assertNull($draft->fresh()->gallery_until);
        $this->assertTrue($draft->fresh()->isArchivedFor($this->armen));
        $this->actingAs($this->armen)->get('http://xcar.localhost/offers/'.$draft->number)->assertOk()->assertSeeText('в архиве');

        // Просят оценить — это «Нужно оценить», а не архив: обычная страница с оценкой.
        Estimate::create(['offer_id' => $draft->id, 'user_id' => $this->armen->id, 'state' => EstimateState::Asked, 'due_at' => now()->addDay()]);
        $this->assertFalse($draft->fresh()->isArchivedFor($this->armen));
        $this->actingAs($this->armen)->get('http://xcar.localhost/offers/'.$draft->number)->assertOk()->assertDontSeeText('в архиве');

        // Приём кончился, ждёт решения: для неподтвердившего ушло — в архиве, без «Приём закрыт».
        $closed = $this->openOffer();
        $closed->forceFill(['bids_close_at' => now()->subMinute()])->save();
        $this->assertTrue($closed->fresh()->isArchivedFor($this->armen));
        $this->actingAs($this->armen)->get('http://xcar.localhost/offers/'.$closed->number)->assertOk()->assertSeeText('в архиве')->assertDontSeeText('риём');
    }

    public function test_regions_filter_not_lock(): void
    {
        $region = DB::table('regions')->orderBy('id')->value('id');
        $other = DB::table('regions')->where('id', '!=', $region)->orderBy('id')->value('id');
        $this->offer->forceFill(['region_id' => $other])->save();
        $this->armen->forceFill(['offer_regions' => [$region]])->save();
        // Чужой регион: страница по ссылке открывается, в списке — только с выключенным «Все регионы».
        $this->assertTrue($this->offer->fresh()->isArchivedFor($this->armen->fresh()));
        $this->actingAs($this->armen->fresh())->get('http://xcar.localhost/offers/archive')->assertDontSee('/offers/'.$this->offer->number.'"', false);
        $this->actingAs($this->armen->fresh())->get('http://xcar.localhost/offers/archive?regions=0')->assertSee('/offers/'.$this->offer->number, false);
    }

    public function test_published_deal_stays_after_it_ends(): void
    {
        // Ольга видела машину только публикацией сделки; показ кончился, сделку закрыли — архив её помнит.
        app(PublishDeal::class)($this->offer->fresh(), [['type' => 'user', 'id' => $this->olga->id, 'delay' => 0]], now()->addHour(), $this->admin);
        $this->assertTrue(Offer::siteList($this->olga)->whereKey($this->offer->id)->exists(), 'на витрине, пока идёт показ');
        $this->travel(2)->hours();
        $this->assertTrue($this->offer->fresh()->isArchivedFor($this->olga));
        app(ChangeOfferState::class)($this->offer->fresh(), OfferState::Cancelled, $this->admin);
        $this->assertNotNull($this->offer->fresh()->deal_shown_until);
        $this->assertTrue($this->offer->fresh()->isArchivedFor($this->olga));
    }
}
