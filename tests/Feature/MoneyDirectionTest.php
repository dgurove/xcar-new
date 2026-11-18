<?php

namespace Tests\Feature;

use App\Billing\Actions\IssueInvoice;
use App\Billing\Actions\RecordPayment;
use App\Billing\ChargeKind;
use App\Billing\DealMoney;
use App\Billing\Invoice;
use App\Billing\ManagerLedger;
use App\Billing\Party;
use App\Billing\PaymentSource;
use App\Garage\Actions\AddCost;
use App\Garage\Actions\SettleGarageCar;
use App\Garage\Car;
use App\Garage\CarState;
use App\Garage\GaragePayer;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\SaveDealContract;
use App\Offers\BidKind;
use App\Offers\DealContract;
use App\Offers\DealScheme;
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
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Кто кому должен (06.10.2026, владелец: «почему мы должны Бородину 1 690 000» — должен был он). Ошибка здесь —
 * лишняя выплата менеджеру, выплата под видом «Отдать нам» или сумма над списком, которой нет в списке.
 */
class MoneyDirectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private function offer(int $floor = 944000, string $format = 'commission'): Offer
    {
        Storage::fake('private');
        Notification::fake();
        config(['xcar.yookassa.shop_id' => '1', 'xcar.yookassa.secret' => 'test']);
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Бородин Филипп', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => $format]);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => $floor, 'asking_price' => 1050000, 'garage_allowed' => true, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());

        return $offer->fresh();
    }

    /** Вознаграждения менеджеру мы не выплачиваем (18.11.2026): оплатили все счета, и ещё счёт — выплаты нет, «должны ему» — ноль. */
    public function test_paid_deal_never_owes_manager(): void
    {
        $offer = $this->offer();
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer, $this->manager, 1050000), $this->admin, 30000, scheme: DealScheme::Prime);
        // Совкомбанк: счёт и договор — после «Автомобиль забрал».
        app(TakeExit::class)($offer->fresh(), $offer->fresh()->position(Track::Sale)->stage->exits->firstWhere('label', 'Автомобиль забрал'), Actor::Manager, $this->manager);
        app(SaveDealContract::class)(DealContract::for($deal->fresh()), ['buyer_id' => 'new', 'new_buyer' => ['phone' => '+7 900 555-44-33'], 'buyer' => ['kind' => 'person',
            'name' => 'Покупаев Пётр Петрович', 'birth_at' => '1985-05-05', 'passport' => '11 11 111111', 'passport_issued' => 'ГУ МВД', 'passport_issued_at' => '2010-10-10', 'reg_address' => 'г. Москва']], $this->manager);
        $this->passportFor($deal->fresh()->contract->buyer);
        $sale = $deal->fresh()->issuedInvoices()->sole();
        $this->assertEqualsWithDelta(1020000, $sale->total, 0.01);
        app(RecordPayment::class)($sale, $this->admin, 1020000, now(), PaymentSource::Bank);
        $this->assertSame(0, Invoice::where('deal_id', $deal->id)->where('direction', 'owed')->count());

        $extra = app(IssueInvoice::class)(Party::forUser($this->manager), $this->admin, 'issued', ChargeKind::Other, now()->addDays(3),
            lines: [['title' => 'Хранение', 'qty' => 1, 'unit' => 'pc', 'price' => 5000.0, 'kind' => ChargeKind::Other->value]], dealId: $deal->id, offerId: $offer->id);
        app(RecordPayment::class)($extra->fresh(), $this->admin, 5000, now(), PaymentSource::Bank);

        $this->assertSame(0, Invoice::where('deal_id', $deal->id)->where('direction', 'owed')->count());
        $this->assertEqualsWithDelta(0, (new ManagerLedger($this->manager))->position()['payout'], 0.01);
        $this->assertSame('Вознаграждение', DealMoney::of($deal->fresh())->phrase);
    }

    /** Гараж «платит менеджер»: два счёта (машина и доля) — строка и пилюля показывают одну сумму, сотруднику «должен нам». */
    public function test_position_is_sum_of_rows(): void
    {
        // Не комиссия: гаражная Совкомбанка счетов при принятии не получает.
        $offer = $this->offer(1300000, 'supplier');
        $this->passportFor($this->manager);
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer, $this->manager, null, kind: BidKind::Garage), $this->admin, payer: GaragePayer::Manager, scheme: DealScheme::Prime, share: 50000);
        $this->sendToManager($deal);

        $ledger = new ManagerLedger($this->manager);
        $row = $ledger->rows()->firstWhere('id', $deal->id);
        $this->assertSame('pay', $row->money->preset);
        $this->assertEqualsWithDelta(1350000, $row->money->amount, 0.01);
        $this->assertEqualsWithDelta(1350000, $ledger->position()['pay'], 0.01);
        $this->assertEqualsWithDelta(1350000, $ledger->sums()['pay'], 0.01);

        $staff = DealMoney::of($deal->fresh(), staff: true);
        $this->assertSame('должен нам', $staff->caption);
        $this->assertStringStartsWith('Оплатит до', $staff->phrase);
        // Оба счёта — одному контрагенту менеджера, а не двум (`Party::forUser` дважды в одной операции).
        $this->assertSame([$this->manager->fresh()->party_id], $deal->fresh()->invoices->pluck('party_id')->unique()->values()->all());
    }

    /** Продали в минус — выплаты менеджеру нет (18.11.2026): расчёт закрывается без документа. */
    public function test_garage_loss_closes_without_payout(): void
    {
        $offer = $this->offer(900000);
        $car = Car::create(['offer_id' => $offer->id, 'manager_id' => $this->manager->id, 'state' => CarState::Selling, 'taken_at' => now(), 'cost' => 900000]);
        app(AddCost::class)($car, ['title' => 'Кузов', 'amount' => 300000], $this->manager);
        $car->forceFill(['state' => CarState::Sold, 'sold_at' => now(), 'sold_price' => 200000])->save();

        $this->assertNull(app(SettleGarageCar::class)($car->fresh(), $this->admin, 10000));
        $this->assertSame(CarState::Settled, $car->fresh()->state);
        $this->assertSame(0, Invoice::where('direction', 'owed')->count());
        $this->assertNull(DealMoney::garage($car->fresh(), $this->manager->fresh()));
    }
}
