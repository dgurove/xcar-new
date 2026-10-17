<?php

namespace Tests\Feature;

use App\Logistics\Actions\AssignExecutor;
use App\Logistics\Actions\Deliver;
use App\Logistics\Actions\PickUp as LogisticsPickUp;
use App\Logistics\Executor;
use App\Logistics\Point;
use App\Logistics\Transport;
use App\Billing\Actions\RecordPayment;
use App\Billing\Actions\VoidInvoice;
use App\Billing\Actions\VoidPayment;
use App\Billing\Invoice;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Billing\PaymentSource;
use App\Garage\Actions\AddCost;
use App\Garage\Actions\EnsurePickup;
use App\Garage\Actions\IssueGaragePayout;
use App\Garage\Actions\MarkGarageSold;
use App\Garage\Actions\MoveCar;
use App\Garage\Actions\SettleGarageCar;
use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\GaragePayer;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\BidKind;
use App\Offers\CarPlace;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Position;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Гараж через подтверждение: гаражная сделка «платим мы» идёт гаражной веткой маршрута и кончается не «Выдан», а «В
 * гараже»; машину везёт вывоз, довёз — подготовка (06.10.2026: «Доставки» нет); продали её покупателю — после его оплаты
 * менеджеру к выплате ровно его расходы плюс вознаграждение, выплатили — расчёт закрыт. Ошибка тут молчалива и дорога
 * (чужая ветка, лишние деньги) — поэтому тест.
 */
class GarageByConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_garage_confirmation_runs_route_to_garage_and_pays_manager_back(): void
    {
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
        $this->assertNull($bid->amount);
        $deal = app(AcceptBid::class)($bid, $admin, payer: GaragePayer::Us);
        $this->assertSame(CarState::Waiting, Car::where('offer_id', $offer->id)->firstOrFail()->state);

        // По маршруту: обычной ветки («Беру в работу») гаражной сделке не видно, гаражная доводит до гаража.
        $seen = [];
        for ($i = 0; $i < 10 && ($o = $offer->fresh())->state === OfferState::Sold; $i++) {
            $stage = $o->stage();
            $seen = [...$seen, ...$stage->exitsFor(Actor::Manager, $deal)->pluck('label')];
            $exit = $stage->exitsFor(Actor::Manager, $deal)->first(fn ($e) => $e->label !== 'Я передумал');
            $exit
                ? app(TakeExit::class)($o, $exit, Actor::Manager, $manager)
                : app(TakeExit::class)($o, $stage->exitsFor(Actor::Staff, $deal)->first(fn ($e) => ! str_contains($e->label, 'отказал')), Actor::Staff, $admin);
        }
        $this->assertContains('Забираю в гараж', $seen);
        $this->assertNotContains('Беру в работу', $seen);
        $this->assertSame(OfferState::Garage, $offer->fresh()->state);
        $this->assertSame(DealState::Done, $deal->fresh()->state);
        $car = Car::where('offer_id', $offer->id)->firstOrFail();
        $this->assertSame(CarState::Waiting, $car->state);
        $this->assertSame(900000, $car->base());

        // Машину везёт вывоз — задача логистики, исполнитель — сам менеджер; «Забрал» к себе в гараж — подготовка.
        app(EnsurePickup::class)($offer->fresh(), $admin, $manager);
        $transport = Transport::openFor($offer);
        $this->assertSame($manager->id, $transport->executor_id);
        app(LogisticsPickUp::class)($transport, ['keys' => 2, 'destination' => 'keeper'], $admin);
        $car->refresh();
        $this->assertSame(CarState::Repair, $car->state);
        app(AddCost::class)($car, ['title' => 'Запчасти', 'amount' => 50000], $manager);
        // Сотрудник ведёт этапы в обе стороны, менеджер — только вперёд своей кнопкой.
        app(MoveCar::class)($car, CarState::Selling, $admin);
        app(MoveCar::class)($car, CarState::Repair, $admin);
        try {
            app(MoveCar::class)($car, CarState::Selling, $manager);
            $this->fail('менеджер через этап не прыгает');
        } catch (ValidationException) {
        }
        // Сделка закрыта — «Готова» ставит сразу в продажу.
        app(MoveCar::class)($car, CarState::Ready, $manager);
        $this->assertSame(CarState::Selling, $car->fresh()->state);
        app(MarkGarageSold::class)($car, ['sold_price' => 1500000], $manager);
        $buyer = Party::create(['kind' => PartyKind::Person, 'name' => 'Покупатель']);
        $invoice = app(SettleGarageCar::class)($car->fresh(), $admin, 70000, $buyer);
        $this->assertEquals(1500000, $invoice->total);

        app(RecordPayment::class)($invoice->fresh(), $admin, 1500000, null, PaymentSource::Bank);
        $car->refresh();
        $this->assertSame(CarState::Sold, $car->state);
        $this->assertEquals(120000, $car->payoutInvoice->total);
        $this->assertTrue($car->payoutInvoice->isOwed());

        // Аннулировали выплату — машина ждёт её снова, кнопка заводит заново с другим вознаграждением.
        app(VoidInvoice::class)($car->payoutInvoice, $admin);
        $car = $car->fresh();
        $this->assertTrue($car->awaitsPayout());
        $payout = app(IssueGaragePayout::class)($car, $admin, 80000);
        $this->assertEquals(130000, $payout->total);

        app(RecordPayment::class)($payout->fresh(), $admin, 130000, null, PaymentSource::Bank);
        $this->assertSame(CarState::Settled, $car->fresh()->state);

        // Счёт покупателю перевыставили после выплаты — вторая выплата не появляется, расчёт закрывается по прежней.
        $buyerInvoice = $car->fresh()->invoice;
        $buyerInvoice->payments()->get()->each(fn ($p) => app(VoidPayment::class)($p, $admin));
        app(VoidInvoice::class)($buyerInvoice->fresh(), $admin);
        $car = $car->fresh();
        $this->assertSame($payout->id, $car->payout_invoice_id);
        $again = app(SettleGarageCar::class)($car, $admin, 80000, ['kind' => PartyKind::Person, 'name' => 'Покупатель 2']);
        app(RecordPayment::class)($again->fresh(), $admin, 1500000, null, PaymentSource::Bank);
        $this->assertSame(CarState::Settled, $car->fresh()->state);
        $this->assertSame(1, Invoice::where('direction', 'owed')->where('state', '!=', 'void')->count());
    }

    /**
     * Совкомбанк (10.10.2026, владелец: «в гараж совком ваще забирается без оплаты, потом уже при продаже менеджер
     * предлагает цену и мы определяем его комиссию»): после принятия счетов нет, машина едет к менеджеру; довезли — она на
     * подготовке, а сделка ждёт цену. «Продаю» ценой, покупатель от менеджера, вознаграждение от нас — счёт ПРАЙМ на цену
     * продажи покупателю; закрыли сделку — расчёт по машине закрыт, расхода на закупочную нет.
     */
    public function test_sovcombank_garage_picks_up_without_payment_and_sells_through_deal(): void
    {
        Storage::fake('private');
        \Illuminate\Support\Facades\Notification::fake();
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Филипп Бородин', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        $sale = $vendor->fresh()->workflow(Track::Sale);
        $this->assertSame([], $sale->problems());
        $this->assertFalse($sale->stages()->get()->contains(fn ($s) => str_contains($s->name, 'на себя') || str_contains($s->name, 'Уведомили')));

        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1200000, 'garage_allowed' => true, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $sale->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $bid = app(PlaceBid::class)($offer->fresh(), $manager, null, null, BidKind::Garage);

        $this->actingAs($admin)->post('http://'.config('xcar.crm_host').'/confirmations/'.$bid->id.'/accept', ['payer' => 'manager', 'pickup' => 'us'])
            ->assertSessionHasNoErrors();

        $offer->refresh();
        $deal = $offer->deal()->first();
        $this->assertTrue($deal->namesBuyer());
        $this->assertSame('Менеджер забирает автомобиль', $offer->stage()->name);
        $this->assertSame(0, Invoice::where('deal_id', $deal->id)->count());
        // Вывоз — задача логистики к менеджеру в гараж (17.10.2026).
        $transport = Transport::openFor($offer);
        $this->assertSame(Point::Keeper, $transport->destination);
        $this->assertSame($manager->id, $offer->keeper()?->id);

        // Наш человек вывез и довёз машину к менеджеру — подготовка, сделка на оплате ждёт от него цену продажи; счетов нет.
        $driver = User::create(['name' => 'Иван Водитель', 'phone' => '79000000003', 'roles' => [Role::Driver], 'approved_at' => now()]);
        app(AssignExecutor::class)($transport, Executor::Staff, $driver, $admin);
        app(LogisticsPickUp::class)($transport->fresh(), ['keys' => 1, 'destination' => 'keeper'], $admin);
        $this->assertSame(1, $offer->fresh()->keys_count);
        app(Deliver::class)($transport->fresh(), $admin);
        $this->assertSame(CarPlace::Keeper, $offer->fresh()->car_place);
        $car = Car::where('offer_id', $offer->id)->firstOrFail();
        $this->assertSame(CarState::Repair, $car->state);
        $position = $offer->fresh()->position(Track::Sale);
        $this->assertSame('Оплата по счёту ПРАЙМ', $position->stage->name);
        $this->assertSame(\App\Workflow\WaitsFor::Manager, $position->waits_for);
        $this->assertSame('price', $deal->fresh()->invoiceGap());
        $this->assertSame(0, Invoice::where('deal_id', $deal->id)->count());

        // Продавать можно, пока сделка идёт: «Готова» — сразу в продажу, «Продаю» ценой.
        app(MoveCar::class)($car, CarState::Ready, $manager);
        $this->assertSame(CarState::Selling, $car->fresh()->state);
        app(MarkGarageSold::class)($car->fresh(), ['sold_price' => 1500000], $manager);
        $this->assertSame(1500000, (int) $deal->fresh()->amount);
        $this->assertSame('buyer', $deal->fresh()->invoiceGap());

        app(\App\Offers\Actions\SaveDealContract::class)(\App\Offers\DealContract::for($deal->fresh()), ['buyer_id' => 'new', 'new_buyer' => ['phone' => '+7 900 555-44-33'],
            'buyer' => ['kind' => 'person', 'name' => 'Покупаев Пётр Петрович', 'birth_at' => '1985-05-05', 'passport' => '11 11 111111',
                'passport_issued' => 'ГУ МВД', 'passport_issued_at' => '2010-10-10', 'reg_address' => 'г. Москва']], $manager);
        $this->assertSame('commission', $deal->fresh()->invoiceGap());
        $this->assertSame(\App\Workflow\WaitsFor::Us, $offer->fresh()->position(Track::Sale)->waits_for);
        // Ход наш — сотрудникам «Назначьте вознаграждение», а не тишина.
        \Illuminate\Support\Facades\Notification::assertSentTo($admin, \App\Notifications\InvoiceNeededNotice::class);

        $deal->fresh()->update(['commission' => 100000]);
        app(\App\Offers\Actions\SyncDealInvoices::class)($deal->fresh(), $admin);
        // Вознаграждение назначено — дальше скан паспорта покупателя (11.10.2026), со сканом счёт встаёт сам.
        $this->assertSame('passport', $deal->fresh()->invoiceGap());
        $this->passportFor($deal->fresh()->contract->buyer);
        $invoice = $deal->fresh()->issuedInvoices()->sole();
        $this->assertSame('Покупаев Пётр Петрович', $invoice->party->name);
        $this->assertEqualsWithDelta(1500000, $invoice->total, 0.01);
        $this->assertNull($offer->fresh()->position(Track::Sale)->waits_for);

        app(RecordPayment::class)($invoice->fresh(), $admin, 1500000, now(), PaymentSource::Bank);
        $this->drive($offer, Track::Sale, $admin, fn (Offer $o) => $o->state !== OfferState::Sold, $manager);
        $this->assertSame(DealState::Done, $deal->fresh()->state);
        $this->assertSame(CarState::Settled, $car->fresh()->state);
        $this->assertFalse($car->fresh()->costs()->where('kind', \App\Garage\Cost::SUPPLIER)->exists());
        $this->assertSame(1, Invoice::where('deal_id', $deal->id)->where('direction', 'owed')->where('state', '!=', 'void')->count());

        // «Взяли под себя» (платим мы, без менеджера) — не она: машину оплачиваем и оформляем сами, расчёт по машине свой.
        $ours = (new \App\Offers\Deal(['garage_payer' => GaragePayer::Us]))->setRelation('offer', $offer->fresh());
        $this->assertFalse($ours->namesBuyer());
    }

    /** Жать первые не тупиковые кнопки ветки, пока не выполнится условие. */
    private function drive(Offer $offer, Track $track, User $admin, \Closure $until, ?User $manager = null): void
    {
        for ($i = 0; $i < 20 && ! $until($o = $offer->fresh()); $i++) {
            $position = Position::where('offer_id', $o->id)->where('track', $track)->firstOrFail();
            $branch = $o->deal;
            $stage = $position->stage;
            $no = fn ($e) => ! preg_match('/отказ|не поступила|не найден/u', mb_strtolower($e->label));
            $exit = ($manager ? $stage->exitsFor(Actor::Manager, $branch)->first($no) : null)
                ?? $stage->exitsFor(Actor::Staff, $branch)->first($no);
            $this->assertNotNull($exit, 'нечего нажать на «'.$stage->name.'»');
            app(TakeExit::class)($o, $exit, $exit->actor, $exit->actor === Actor::Manager ? $manager : $admin);
        }
        $this->assertTrue($until($offer->fresh()), 'не дошли');
    }
}
