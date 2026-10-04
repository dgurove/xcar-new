<?php

namespace Tests\Feature;

use App\Billing\Actions\RecordPayment;
use App\Billing\Actions\VoidInvoice;
use App\Billing\Actions\VoidPayment;
use App\Billing\Invoice;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Billing\PaymentSource;
use App\Garage\Actions\AddCost;
use App\Garage\Actions\AdvanceCar;
use App\Garage\Actions\IssueGaragePayout;
use App\Garage\Actions\MarkGarageSold;
use App\Garage\Actions\SettleGarageCar;
use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\GaragePayer;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\BidKind;
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
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Гараж через подтверждение: гаражная сделка «платим мы» идёт гаражной веткой маршрута и кончается не «Выдан», а машиной
 * в гараже на доставке; продали её покупателю — после его оплаты менеджеру к выплате ровно его расходы плюс
 * вознаграждение, выплатили — расчёт закрыт. Ошибка тут молчалива и дорога (чужая ветка, лишние деньги) — поэтому тест.
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

        // По маршруту: обычной ветки («Покупаю») гаражной сделке не видно, гаражная доводит до гаража.
        $seen = [];
        for ($i = 0; $i < 10 && ($o = $offer->fresh())->state === OfferState::Sold; $i++) {
            $stage = $o->stage();
            $seen = [...$seen, ...$stage->exitsFor(Actor::Manager, $deal)->pluck('label')];
            $exit = $stage->exitsFor(Actor::Manager, $deal)->first(fn ($e) => $e->label !== 'Отказываюсь');
            $exit
                ? app(TakeExit::class)($o, $exit, Actor::Manager, $manager)
                : app(TakeExit::class)($o, $stage->exitsFor(Actor::Staff, $deal)->first(fn ($e) => ! str_contains($e->label, 'отказал')), Actor::Staff, $admin);
        }
        $this->assertContains('Забираю в гараж', $seen);
        $this->assertNotContains('Покупаю', $seen);
        $this->assertSame(OfferState::Garage, $offer->fresh()->state);
        $this->assertSame(DealState::Done, $deal->fresh()->state);
        $car = Car::where('offer_id', $offer->id)->firstOrFail();
        $this->assertSame(CarState::Delivery, $car->state);
        $this->assertSame(900000, $car->cost);

        app(AdvanceCar::class)($car, $manager);
        app(AddCost::class)($car, ['title' => 'Запчасти', 'amount' => 50000], $manager);
        app(AdvanceCar::class)($car, $manager);
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
}
