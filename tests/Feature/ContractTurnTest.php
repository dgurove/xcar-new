<?php

namespace Tests\Feature;

use App\Chats\Message;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\SaveDealContract;
use App\Offers\Actions\ShowToManager;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealScheme;
use App\Offers\DealTabs;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Position;
use App\Workflow\Preset;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * «Нужно приложить договор» — только после того, как наш договор менеджеру отправлен (27.11.2026, владелец по 495181/2026:
 * «почему написали, если он нами ещё не отправлен? много где такой косяк»). Ошибка молчаливая: менеджеру пишем зря, он не
 * понимает, что подписывать.
 */
class ContractTurnTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private Offer $offer;

    public function test_signed_contract_step_waits_for_us_until_contract_is_sent(): void
    {
        $deal = $this->deal();
        $stage = $this->stage('Подписанный договор');
        app(EnterStage::class)($this->offer->fresh(), $stage);

        $position = $this->position();
        $this->assertSame(WaitsFor::Us, $position->waits_for, 'договор не отправлен — ход наш');
        $this->assertNull($position->deadline_at);
        $this->assertFalse(Requirement::where('deal_id', $deal->id)->whereNull('done_at')->exists(), 'просьбы менеджеру нет');
        $this->assertSame(0, $this->asks($deal));
        // Наше дело — во вкладке «Отправить договор», а не «Не распределено».
        $todo = $position->todo();
        $this->assertNotNull($todo);
        $this->assertSame('contract', DealTabs::of($position, $todo));

        // «Отправить менеджеру» — ход его: просьба и одно сообщение в чат.
        app(ShowToManager::class)->contract($deal->fresh(), $this->admin);
        $position = $this->position();
        $this->assertNull($position->waits_for);
        $this->assertNotNull($position->deadline_at);
        $this->assertSame(1, Requirement::where('deal_id', $deal->id)->whereNull('done_at')->count());
        $this->assertSame(1, $this->asks($deal));

        // Отозвали — снова наш ход, просьба снята.
        app(ShowToManager::class)->recallContract($deal->fresh(), $this->admin);
        $this->assertSame(WaitsFor::Us, $this->position()->waits_for);
        $this->assertFalse(Requirement::where('deal_id', $deal->id)->whereNull('done_at')->exists());
    }

    public function test_sent_contract_asks_right_away(): void
    {
        $deal = $this->deal();
        app(ShowToManager::class)->contract($deal, $this->admin);
        app(EnterStage::class)($this->offer->fresh(), $this->stage('Подписанный договор'));

        $this->assertNull($this->position()->waits_for);
        $this->assertSame(1, Requirement::where('deal_id', $deal->id)->whereNull('done_at')->count());
        $this->assertSame(1, $this->asks($deal));
    }

    private function asks(Deal $deal): int
    {
        // Только просьбы шага договора: до него сделка уже получала свои («оплатите счёт» и т. п.).
        $stage = $this->stage('Подписанный договор');

        return Message::whereIn('requirement_id', Requirement::where('deal_id', $deal->id)->where('stage_id', $stage->id)->select('id'))->count();
    }

    private function position(): Position
    {
        return Position::where('offer_id', $this->offer->id)->where('track', Track::Sale)->with('stage')->sole();
    }

    private function stage(string $name): Stage
    {
        return Stage::where('workflow_id', $this->offer->vendor->workflow(Track::Sale)->id)->where('name', $name)->firstOrFail();
    }

    private function deal(): Deal
    {
        Storage::fake('private');
        Notification::fake();
        $this->app['db']->extend('pgsql_async', fn () => $this->app['db']->connection('pgsql'));
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Роман Корабельников', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        $this->offer = Offer::create(['vendor_id' => $vendor->id, 'supplier_vat' => 5, 'floor_price' => 944000, 'asking_price' => 1050000, 'bids_close_at' => now()->addDay()]);
        $this->offer->forceFill(['state' => OfferState::Open])->save();
        $this->offer->car->update(['vin' => 'LZZ7CCWD8PC593777', 'year' => 2023, 'pts' => '164302109662152', 'color' => 'Белый']);
        $this->offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($this->offer, $this->stage('Приём подтверждений'));
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($this->offer->fresh(), $this->manager, 1050000), $this->admin, 30000, scheme: DealScheme::Prime);
        app(SaveDealContract::class)(DealContract::for($deal->fresh()), ['buyer_id' => 'new', 'new_buyer' => ['phone' => '+7 900 555-44-33'], 'buyer' => ['kind' => 'person',
            'name' => 'Корабельников Роман', 'birth_at' => '1985-05-05', 'passport' => '11 11 111111', 'passport_issued' => 'ГУ МВД', 'passport_issued_at' => '2010-10-10', 'reg_address' => 'г. Москва']], $this->manager);
        $this->passportFor($deal->fresh()->contract->buyer);
        $owner = \App\Billing\Party::create(['kind' => 'company', 'name' => 'ПАО «Совкомбанк»', 'inn' => '4401116480']);
        DealContract::for($deal->fresh())->update(['seller_party_id' => $owner->id]);

        return $deal->fresh();
    }
}
