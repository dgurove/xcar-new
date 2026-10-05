<?php

namespace Tests\Feature;

use App\Billing\Actions\RecordPayment;
use App\Billing\ChargeKind;
use App\Billing\InvoiceState;
use App\Billing\PaymentSource;
use App\Garage\GaragePayer;
use App\Notifications\BuyerNeededNotice;
use App\Notifications\InvoiceNeededNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\SaveDealContract;
use App\Offers\BidKind;
use App\Offers\CommissionMode;
use App\Offers\CommissionState;
use App\Offers\DealContract;
use App\Offers\DealScheme;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Position;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Схемы оплаты без «Выставите счёт» (05.10.2026, Совкомбанк и Альфа): ПРАЙМ — счёт сам, как только менеджер указал
 * покупателя: «Транспортное средство» = закупочная + «Агентское вознаграждение» = разница, без ссылки, вознаграждение
 * к выплате; платит сам менеджер — за вычетом; «страховой напрямую» — подбор по ссылке; гараж «платит менеджер» — счёт
 * за машину и наша доля. Ошибка здесь — не тот счёт не тому человеку.
 */
class PrimeDealFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private function offer(int $floor = 944000): Offer
    {
        Storage::fake('private');
        Notification::fake();
        config(['xcar.yookassa.shop_id' => '1', 'xcar.yookassa.secret' => 'test']);
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Тужиков Евгений', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => $floor, 'asking_price' => 1050000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());

        return $offer->fresh();
    }

    private function buyerData(): array
    {
        return ['buyer_id' => 'new', 'new_buyer' => ['phone' => '+7 900 555-44-33'], 'buyer' => ['kind' => 'person', 'name' => 'Покупаев Пётр Петрович', 'birth_at' => '1985-05-05',
            'passport' => '11 11 111111', 'passport_issued' => 'ГУ МВД', 'passport_issued_at' => '2010-10-10', 'reg_address' => 'г. Москва']];
    }

    public function test_prime_waits_for_buyer_then_invoice_to_buyer_without_link_and_payout(): void
    {
        $offer = $this->offer();
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer, $this->manager, 1050000), $this->admin, 30000, CommissionMode::Payout, scheme: DealScheme::Prime);

        // Шаг оплаты без счёта — ход менеджера «Укажите покупателя», а не наш «Выставите счёт».
        $position = Position::where('offer_id', $offer->id)->where('track', Track::Sale)->with('stage')->firstOrFail();
        $this->assertTrue($position->stage->isPayStep());
        $this->assertSame(WaitsFor::Manager, $position->waits_for);
        $this->assertSame('buyer', $deal->fresh()->invoiceGap());
        Notification::assertSentTo($this->manager, BuyerNeededNotice::class);
        Notification::assertNotSentTo($this->admin, InvoiceNeededNotice::class);

        app(SaveDealContract::class)(DealContract::for($deal->fresh()), $this->buyerData(), $this->manager);

        $invoice = $deal->fresh()->issuedInvoices()->sole();
        $this->assertSame(ChargeKind::Sale, $invoice->kind);
        $this->assertSame('Покупаев Пётр Петрович', $invoice->party->name);
        $this->assertEqualsWithDelta(1050000, $invoice->remaining(), 0.01);
        $this->assertSame([944000.0, 106000.0], $invoice->charges()->orderBy('id')->pluck('price')->map(fn ($p) => (float) $p)->all());
        $this->assertNull($invoice->openLink());
        $this->assertNull(Position::whereKey($position->id)->value('waits_for'));

        app(RecordPayment::class)($invoice->fresh(), $this->admin, 1050000, now(), PaymentSource::Bank);
        $this->assertSame(CommissionState::Payable, $deal->fresh()->commissionState());
    }

    public function test_prime_manager_pays_minus_his_fee(): void
    {
        $offer = $this->offer();
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer, $this->manager, 1050000), $this->admin, 30000, CommissionMode::Withheld, scheme: DealScheme::Prime);
        app(SaveDealContract::class)(DealContract::for($deal->fresh()), ['buyer_id' => 'me'], $this->manager);

        $invoice = $deal->fresh()->issuedInvoices()->sole();
        $this->assertSame($this->manager->fresh()->party_id, $invoice->party_id);
        $this->assertEqualsWithDelta(1020000, $invoice->remaining(), 0.01);
        $this->assertFalse($invoice->isPartial());
    }

    public function test_insurer_direct_selection_by_link(): void
    {
        $offer = $this->offer(779000);
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer, $this->manager, 1050000), $this->admin, 20000, CommissionMode::Payout, scheme: DealScheme::Insurer, ownerPrice: 750000);

        $invoice = $deal->fresh()->issuedInvoices()->sole();
        $this->assertSame(ChargeKind::Selection, $invoice->kind);
        $this->assertEqualsWithDelta(1050000 - 750000 - 20000, $invoice->total, 0.01);
        $this->assertNotNull($invoice->openLink());
        $this->assertFalse($deal->fresh()->hasContract());
    }

    public function test_garage_manager_pays_car_by_invoice_and_share_by_link(): void
    {
        $offer = $this->offer(1300000);
        $bid = app(PlaceBid::class)($offer, $this->manager, null, kind: BidKind::Garage);
        $deal = app(AcceptBid::class)($bid, $this->admin, payer: GaragePayer::Manager, scheme: DealScheme::Prime, share: 50000);

        $invoices = $deal->fresh()->issuedInvoices()->get()->keyBy(fn ($i) => $i->kind->value);
        $this->assertEqualsWithDelta(1300000, $invoices['sale']->total, 0.01);
        $this->assertNull($invoices['sale']->openLink());
        $this->assertEqualsWithDelta(50000, $invoices['selection']->total, 0.01);
        $this->assertNotNull($invoices['selection']->openLink());
        $this->assertSame(InvoiceState::Issued, $invoices['selection']->state);
    }
}
