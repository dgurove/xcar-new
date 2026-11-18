<?php

namespace Tests\Feature;

use App\Billing\Bank\Actions\MatchTransaction;
use App\Billing\Bank\Actions\SortUnmatched;
use App\Billing\Bank\Transaction;
use App\Garage\CarState;
use App\Garage\GaragePayer;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\BidKind;
use App\Offers\Deal;
use App\Offers\DealScheme;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Гаражная продажа догадкой по выписке (18.11.2026): деньги — ошибка тут ставит неверную цену продажи и вознаграждение.
 * Платёж покупателя — «Продана» за его сумму с пометкой «догадка» в «Истории»; платёж менеджера, вписанная руками цена —
 * не трогаем; поступление после догадки цену не меняет. Непривязанное с VIN без сделки — «Нет в CRM».
 */
class SellFromStatementTest extends TestCase
{
    use RefreshDatabase;

    private const VIN = 'LB37852D9RS267309';

    private User $admin;

    private User $manager;

    private function deal(): Deal
    {
        Storage::fake('private');
        Notification::fake();
        config(['xcar.mercure.hub' => '', 'xcar.yookassa.shop_id' => '1', 'xcar.yookassa.secret' => 'test']);
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Роман Мирошников', 'last_name' => 'Мирошников', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'vin' => self::VIN, 'floor_price' => 1000000, 'asking_price' => 1300000, 'garage_allowed' => true, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $this->manager, null, kind: BidKind::Garage), $this->admin, payer: GaragePayer::Manager, scheme: DealScheme::Prime);
        $deal->garageCar->update(['state' => CarState::Repair]);

        return $deal->fresh();
    }

    private function pay(int $amount, string $from, string $day = '2026-11-16', ?string $purpose = null): Transaction
    {
        $tx = Transaction::create(['external_id' => uniqid(), 'account' => '1', 'booked_at' => $day, 'direction' => 'in', 'amount' => $amount,
            'counterparty' => $from, 'counterparty_inn' => '7707083893', 'purpose' => $purpose ?? 'Оплата ТС GEELY MONJARO vin:'.self::VIN, 'state' => Transaction::UNMATCHED]);

        return app(MatchTransaction::class)($tx);
    }

    private function soldLine(Deal $deal): ?string
    {
        return $deal->offer->events()->where('type', OfferEventType::Garage)->get()
            ->map->text()->first(fn ($t) => str_starts_with($t, 'Продана'));
    }

    public function test_buyer_payment_sells_with_guess_in_history(): void
    {
        $deal = $this->deal();
        $this->pay(1250000, 'ПАО Сбербанк//ХОЛОПОВА ИРИНА ВЛАДИМИРОВНА//40817');

        $deal = $deal->fresh();
        $this->assertSame(CarState::Sold, $deal->garageCar->state);
        // Пришло 1 250 000: цена по догадке — оно же, вознаграждение не знаем (0), услуги подбора — 250 000.
        $reward = 0;
        $this->assertSame(1250000 + $reward, (int) $deal->garageCar->sold_price);
        $this->assertSame(1250000 + $reward, (int) $deal->amount);
        $this->assertSame($reward, (int) $deal->commission);
        // Счёт ПРАЙМ — ровно пришедшее, закрыт им; переплаты нет.
        $sale = $deal->issuedInvoices()->sole();
        $this->assertEqualsWithDelta(1250000, $sale->total, 0.01);
        $this->assertSame(\App\Billing\InvoiceState::Paid, $sale->state);
        $this->assertSame(0.0, $deal->creditExtra());
        $this->assertStringContainsString('догадка по выписке', (string) $this->soldLine($deal));
        $this->assertStringContainsString('Холопова Ирина Владимировна', (string) $this->soldLine($deal));

        // Ещё поступление — цена прежняя, в «Истории» — проверьте.
        $this->pay(50000, 'ПАО Сбербанк//ХОЛОПОВА ИРИНА ВЛАДИМИРОВНА//40817', '2026-11-17');
        $this->assertSame(1250000 + $reward, (int) $deal->fresh()->garageCar->sold_price);
        $this->assertTrue($deal->offer->events()->where('type', OfferEventType::Note)->get()->contains(fn ($e) => str_contains($e->text(), 'После продажи по догадке')));
    }

    public function test_manager_payment_and_hand_price_are_left_alone(): void
    {
        $deal = $this->deal();
        $this->pay(1250000, 'ПАО Сбербанк//МИРОШНИКОВ РОМАН АНДРЕЕВИЧ//40817');
        $this->assertNotSame(CarState::Sold, $deal->fresh()->garageCar->state);

        $other = $this->deal2WithHandPrice();
        $this->pay(1250000, 'ПАО Сбербанк//ХОЛОПОВА ИРИНА ВЛАДИМИРОВНА//40817', purpose: 'Оплата ТС vin:XTA21140064123456');
        $this->assertNotSame(CarState::Sold, $other->fresh()->garageCar->state);
    }

    public function test_loss_is_not_guessed(): void
    {
        $deal = $this->deal();
        $this->pay(300000, 'ИП ЖУРАВЛЕВА ЭЛИНА РАДИКОВНА');
        $this->assertNotSame(CarState::Sold, $deal->fresh()->garageCar->state);
    }

    public function test_vin_without_deal_is_marked_no_crm(): void
    {
        $this->deal();
        $tx = $this->pay(1437000, 'КИНДЕЕВ ЛЕОНИД ЮРЬЕВИЧ', purpose: 'ОПЛАТА ТС BELGEE X70 VIN: Y4K8722D3SB326069');
        $tx = app(SortUnmatched::class)($tx->fresh());
        $this->assertSame(Transaction::IGNORED, $tx->state);
        $this->assertSame(SortUnmatched::NO_CRM, $tx->note);
    }

    /** Вторая гаражная — с ценой, вписанной руками. */
    private function deal2WithHandPrice(): Deal
    {
        $vendor = Vendor::where('name', 'Совкомбанк тест')->first();
        $offer = Offer::create(['vendor_id' => $vendor->id, 'vin' => 'XTA21140064123456', 'floor_price' => 1000000, 'asking_price' => 1300000, 'garage_allowed' => true, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $this->manager, null, kind: BidKind::Garage), $this->admin, payer: GaragePayer::Manager, scheme: DealScheme::Prime);
        $deal->garageCar->update(['state' => CarState::Repair]);
        $deal->update(['amount' => 1400000]);

        return $deal->fresh();
    }
}
