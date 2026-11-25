<?php

namespace Tests\Feature;

use App\Billing\Actions\RecordPayment;
use App\Billing\ChargeKind;
use App\Billing\InvoiceState;
use App\Billing\Party;
use App\Billing\PaymentSource;
use App\Garage\Car as GarageCar;
use App\Garage\CarState;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\CancelDeal;
use App\Offers\Actions\Give;
use App\Offers\Actions\PlaceBid;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealScheme;
use App\Offers\ExtraAsk;
use App\Offers\Giving;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Reprice;
use App\Offers\RepriceAsk;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\StepBack;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Переприём сделки (26.11.2026, владелец: «возьмись и за договор с переоценкой»): договор со стороны машины, доп. просьбы и
 * переоценка переходят к новой сделке; про покупателя — только тому же менеджеру. И 779934/2026: откат шага не уводит идущую
 * сделку на выбор подтверждения, оплата счёта клиентом покупателя с гаражом — продажа. Ошибки молчаливые: данные и вопросы
 * пропадали без следа.
 */
class ReacceptCarryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Vendor $vendor;

    public function test_other_manager_gets_car_side_contract_asks_and_reprice(): void
    {
        $deal = $this->deal();
        $old = DealContract::for($deal);
        $old->forceFill(['commission_no' => '511631/2026', 'supplier_price' => 900000, 'city' => 'Тула'])->save();
        $ask = ExtraAsk::create(['offer_id' => $deal->offer_id, 'deal_id' => $deal->id, 'text' => 'ПТС', 'asks_manager' => true, 'asked_at' => now(),
            'since_at' => now(), 'answered_at' => now(), 'shown_at' => now()]);
        $reprice = Reprice::create(['offer_id' => $deal->offer_id, 'from_price' => 944000, 'to_price' => 960000, 'source' => 'letter', 'state' => Reprice::APPLIED]);
        $question = RepriceAsk::create(['reprice_id' => $reprice->id, 'offer_id' => $deal->offer_id, 'deal_id' => $deal->id, 'user_id' => $deal->buyer_id,
            'from_price' => 944000, 'to_price' => 960000, 'state' => RepriceAsk::PENDING]);

        $other = $this->manager('Трофимов Олег', '79000000003');
        $again = app(Give::class)(new Giving($deal->offer->fresh(), $other, amount: 1060000, scheme: DealScheme::Prime, commission: 30000), $this->admin);

        $this->assertSame($deal->id, $again->from_deal_id);
        $new = $again->fresh()->contract;
        $this->assertSame('511631/2026', $new->commission_no, 'договор комиссии — машины');
        $this->assertSame(900000, (int) $new->supplier_price);
        $this->assertNull($new->city, 'город договора — покупателя, другому не переходит');
        $this->assertSame($again->id, $ask->fresh()->deal_id, 'доп. просьба — новой сделке');
        $this->assertNull($ask->fresh()->shown_at, 'новому менеджеру ответ показываем заново');
        $this->assertSame(RepriceAsk::VOID, $question->fresh()->state, 'вопрос прежнему менеджеру погас');
        $reprice->forceFill(['created_at' => $deal->created_at->copy()->addMinute()])->save();
        $this->assertTrue($again->fresh()->since()->equalTo($deal->created_at), 'переоценка при прежней сделке — и этой');
    }

    public function test_same_manager_after_cancel_keeps_his_question_and_buyer_side(): void
    {
        $deal = $this->deal();
        DealContract::for($deal)->forceFill(['city' => 'Тула'])->save();
        $reprice = Reprice::create(['offer_id' => $deal->offer_id, 'from_price' => 944000, 'to_price' => 960000, 'source' => 'letter', 'state' => Reprice::APPLIED]);
        $question = RepriceAsk::create(['reprice_id' => $reprice->id, 'offer_id' => $deal->offer_id, 'deal_id' => $deal->id, 'user_id' => $deal->buyer_id,
            'from_price' => 944000, 'to_price' => 960000, 'state' => RepriceAsk::PENDING]);
        app(CancelDeal::class)($deal->fresh(), $this->admin, keepBid: true);
        $deal->offer->fresh()->forceFill(['state' => OfferState::Open])->save();

        $again = app(Give::class)(new Giving($deal->offer->fresh(), $deal->buyer, amount: 1050000, scheme: DealScheme::Prime, commission: 30000), $this->admin);

        $this->assertSame($deal->id, $again->from_deal_id, 'отменённая только что — та же продажа');
        $this->assertSame($again->id, $question->fresh()->deal_id);
        $this->assertSame('Тула', $again->fresh()->contract->city);
    }

    public function test_step_back_does_not_leave_a_live_deal_on_bid_choice(): void
    {
        $deal = $this->deal();
        $offer = $deal->offer->fresh();
        $this->assertNotSame('Выбор подтверждения', $offer->position(Track::Sale)->stage->name);
        $this->assertNull(StepBack::undoable($offer, Track::Sale), 'с первого шага сделки назад на выбор — нет');
        $choice = Stage::where('workflow_id', $offer->position(Track::Sale)->stage->workflow_id)->where('name', 'Выбор подтверждения')->firstOrFail();
        $this->assertTrue(Stage::dealLocked($offer, $choice));
    }

    public function test_client_payment_marks_garage_buyer_car_sold_and_settled(): void
    {
        $deal = $this->deal(open: true);
        $holder = User::create(['name' => 'Ананьев Владимир', 'phone' => '79000000009', 'roles' => [Role::Buyer], 'approved_at' => now(), 'garage_enabled' => true]);
        $offer = $deal->offer->fresh();
        app(CancelDeal::class)($deal->fresh(), $this->admin);
        $offer->fresh()->forceFill(['state' => OfferState::Open])->save();
        $garage = app(Give::class)(new Giving($offer->fresh(), $holder, garage: true, buyerPrice: 2700000), $this->admin);
        $car = GarageCar::where('deal_id', $garage->id)->firstOrFail();
        $car->update(['state' => CarState::Selling]);
        $client = Party::create(['kind' => \App\Billing\PartyKind::Person, 'name' => 'Гречишкин Константин Вячеславович']);
        $garage->forceFill(['payer_party_id' => $client->id])->save();
        app(\App\Offers\Actions\SyncDealInvoices::class)($garage->fresh(), $this->admin);
        $invoice = $garage->fresh()->issuedInvoices()->where('kind', ChargeKind::Sale)->where('state', '!=', InvoiceState::Void)->sole();
        $this->assertSame($client->id, $invoice->party_id);

        app(RecordPayment::class)($invoice, $this->admin, 2700000, now(), PaymentSource::Bank);

        $this->assertSame(CarState::Settled, $car->fresh()->state, 'клиент оплатил — продана и расчёт закрыт');
        $this->assertSame('Гречишкин Константин Вячеславович', $car->fresh()->buyer_name);
    }

    private function manager(string $name, string $phone): User
    {
        $m = User::create(['name' => $name, 'phone' => $phone, 'roles' => [Role::Manager], 'approved_at' => now()]);

        return $m;
    }

    private function deal(bool $open = false): Deal
    {
        Storage::fake('private');
        Notification::fake();
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = $this->manager('Тужиков Евгений', '79000000002');
        $this->vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        app(ApplyPreset::class)($this->vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        $offer = Offer::create(['vendor_id' => $this->vendor->id, 'floor_price' => 944000, 'asking_price' => 1050000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $this->vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());

        return app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $manager, 1050000), $this->admin, 30000, scheme: DealScheme::Prime)->fresh();
    }
}
