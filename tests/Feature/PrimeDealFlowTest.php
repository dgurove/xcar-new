<?php

namespace Tests\Feature;

use App\Billing\Actions\IssueServiceInvoice;
use App\Billing\Actions\RecordPayment;
use App\Billing\ServiceTitle;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\PaymentSource;
use App\Garage\GaragePayer;
use App\Notifications\BuyerNeededNotice;
use App\Notifications\InvoiceNeededNotice;
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
 * покупателя: одной строкой «Транспортное средство» на цену минус вознаграждение (выплат менеджеру нет, 18.11.2026), без
 * ссылки, кто бы ни платил; «страховой напрямую» — подбор по ссылке; гараж «платит менеджер» — счёт за машину и наша доля. Ошибка здесь — не тот счёт не тому человеку.
 */
class PrimeDealFlowTest extends TestCase
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
        $this->manager = User::create(['name' => 'Тужиков Евгений', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => $format]);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => $floor, 'asking_price' => 1050000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());

        return $offer->fresh();
    }

    /** Совкомбанк: машину забирают без оплаты, счёт и договор — после «Автомобиль забрал». */
    private function pickedUp(Offer $offer): void
    {
        $stage = $offer->fresh()->position(Track::Sale)->stage;
        $exit = $stage->exits->firstWhere('label', 'Автомобиль забрал');
        $this->assertNotNull($exit, 'шаг «'.$stage->name.'» без «Автомобиль забрал»');
        app(TakeExit::class)($offer->fresh(), $exit, Actor::Manager, $this->manager);
    }

    private function buyerData(): array
    {
        return ['buyer_id' => 'new', 'new_buyer' => ['phone' => '+7 900 555-44-33'], 'buyer' => ['kind' => 'person', 'name' => 'Покупаев Пётр Петрович', 'birth_at' => '1985-05-05',
            'passport' => '11 11 111111', 'passport_issued' => 'ГУ МВД', 'passport_issued_at' => '2010-10-10', 'reg_address' => 'г. Москва']];
    }

    public function test_prime_waits_for_buyer_then_invoice_to_buyer_without_link_and_no_payout(): void
    {
        $offer = $this->offer();
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer, $this->manager, 1050000), $this->admin, 30000, scheme: DealScheme::Prime);
        // До получения счетов нет и покупателя никто не спрашивает.
        $this->assertSame('Забрать автомобиль', $offer->fresh()->stage()->name);
        $this->assertSame(0, $deal->fresh()->issuedInvoices()->count());
        $this->pickedUp($offer);

        // Шаг оплаты без счёта — ход менеджера «Укажите покупателя», а не наш «Выставите счёт».
        $position = Position::where('offer_id', $offer->id)->where('track', Track::Sale)->with('stage')->firstOrFail();
        $this->assertTrue($position->stage->isPayStep());
        $this->assertSame(WaitsFor::Manager, $position->waits_for);
        $this->assertSame('buyer', $deal->fresh()->invoiceGap());
        Notification::assertSentTo($this->manager, BuyerNeededNotice::class);
        Notification::assertNotSentTo($this->admin, InvoiceNeededNotice::class);

        app(SaveDealContract::class)(DealContract::for($deal->fresh()), $this->buyerData(), $this->manager);
        // Покупатель указан, паспорт вписан, а скана нет — счёт уже есть (18.11.2026: ему нужны ФИО и прописка), договору скан нужен.
        $this->assertSame(1, $deal->fresh()->issuedInvoices()->where('kind', ChargeKind::Sale)->count());
        $this->assertContains('скан паспорта покупателя', DealContract::for($deal->fresh())->missing());
        $this->passportFor($deal->fresh()->contract->buyer);

        $invoice = $deal->fresh()->issuedInvoices()->sole();
        $this->assertSame(ChargeKind::Sale, $invoice->kind);
        $this->assertSame('Покупаев Пётр Петрович', $invoice->party->name);
        // Одна строка на цену минус вознаграждение (18.11.2026: вознаграждения мы не выплачиваем никогда, наш счёт — за вычетом
        // его; закупочной в счёте не видно).
        $this->assertEqualsWithDelta(1020000, $invoice->remaining(), 0.01);
        $this->assertSame([1020000.0], $invoice->charges()->orderBy('id')->pluck('price')->map(fn ($p) => (float) $p)->all());
        $this->assertNull($invoice->openLink());
        // Счёт встал — ход наш, пока сотрудник не отправит его менеджеру (16.11.2026); отправил — ход менеджера.
        $this->assertSame(WaitsFor::Us, Position::whereKey($position->id)->first()->waits_for);
        $this->sendToManager($deal);
        $this->assertNull(Position::whereKey($position->id)->value('waits_for'));

        // Оплатили — выплаты менеджеру нет.
        app(RecordPayment::class)($invoice->fresh(), $this->admin, 1020000, now(), PaymentSource::Bank);
        $this->assertSame(0, Invoice::where('direction', 'owed')->count());
    }

    /** Разовая оплата по ссылке с машиной сделки ПРАЙМ ложится в её счёт зачётом; строка счёта та же (09.10.2026). */
    public function test_prime_invoice_takes_manual_link_credit(): void
    {
        $offer = $this->offer();
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer, $this->manager, 1050000), $this->admin, 30000, scheme: DealScheme::Prime);
        $this->pickedUp($offer);
        $manual = app(IssueServiceInvoice::class)(['name' => 'Покупаев Пётр Петрович'], $this->admin, 100000, ServiceTitle::DEFAULT, $offer);
        app(RecordPayment::class)($manual->fresh(), $this->admin, 100000, now(), PaymentSource::Bank);

        app(SaveDealContract::class)(DealContract::for($deal->fresh()), $this->buyerData(), $this->manager);
        $this->passportFor($deal->fresh()->contract->buyer);

        $invoice = $deal->fresh()->issuedInvoices()->sole();
        $this->assertSame([1020000.0], $invoice->charges()->orderBy('id')->pluck('price')->map(fn ($p) => (float) $p)->all());
        $this->assertEqualsWithDelta(920000, $invoice->remaining(), 0.01);
        $this->assertEqualsWithDelta(100000, (float) $invoice->payments()->where('source', PaymentSource::Credit)->sum('amount'), 0.01);
    }

    public function test_prime_manager_pays_minus_his_fee(): void
    {
        $offer = $this->offer();
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer, $this->manager, 1050000), $this->admin, 30000, scheme: DealScheme::Prime);
        $this->pickedUp($offer);
        app(SaveDealContract::class)(DealContract::for($deal->fresh()), ['buyer_id' => 'me'], $this->manager);
        $this->passportFor($this->manager);

        $invoice = $deal->fresh()->issuedInvoices()->sole();
        $this->assertSame($this->manager->fresh()->party_id, $invoice->party_id);
        $this->assertEqualsWithDelta(1020000, $invoice->remaining(), 0.01);
        $this->assertFalse($invoice->isPartial());
    }

    public function test_insurer_direct_selection_by_link(): void
    {
        // Не по комиссии: у Совкомбанка с 14.11.2026 только ПРАЙМ (`DealScheme::casesFor`).
        $offer = $this->offer(779000, 'supplier');
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer, $this->manager, 1050000), $this->admin, 20000, scheme: DealScheme::Insurer, ownerPrice: 750000);
        $this->pickedUp($offer);
        $this->sendToManager($deal);

        $invoice = $deal->fresh()->issuedInvoices()->sole();
        $this->assertSame(ChargeKind::Selection, $invoice->kind);
        $this->assertEqualsWithDelta(1050000 - 750000 - 20000, $invoice->total, 0.01);
        $this->assertNotNull($invoice->openLink());
        $this->assertFalse($deal->fresh()->hasContract());
    }

    public function test_garage_manager_pays_car_by_invoice_and_share_by_link(): void
    {
        // Не комиссия: у гаражной Совкомбанка счёта за машину при принятии нет (`SovcomPathTest`).
        $offer = $this->offer(1300000, 'supplier');
        // Гаражной «платит менеджер» покупатель по ДКП — сам менеджер: счёт за машину — с его паспортом и сканом.
        $this->passportFor($this->manager);
        $bid = app(PlaceBid::class)($offer, $this->manager, null, kind: BidKind::Garage);
        $deal = app(AcceptBid::class)($bid, $this->admin, payer: GaragePayer::Manager, scheme: DealScheme::Prime, share: 50000);
        $this->sendToManager($deal);

        $invoices = $deal->fresh()->issuedInvoices()->get()->keyBy(fn ($i) => $i->kind->value);
        $this->assertEqualsWithDelta(1300000, $invoices['sale']->total, 0.01);
        $this->assertNull($invoices['sale']->openLink());
        $this->assertEqualsWithDelta(50000, $invoices['selection']->total, 0.01);
        $this->assertNotNull($invoices['selection']->openLink());
        $this->assertSame(InvoiceState::Issued, $invoices['selection']->state);

        // Заплатил долю — шаг оплаты ждёт, пока не оплачена и машина (ревью 05.10.2026).
        $stage = $offer->fresh()->stage()->name;
        app(RecordPayment::class)($invoices['selection']->fresh(), $this->admin, 50000, now(), PaymentSource::Acquiring);
        $this->assertSame($stage, $offer->fresh()->stage()->name);
    }

    /**
     * ПРАЙМ: счёт и договор за вычетом вознаграждения, кто бы ни платил — покупатель или сам менеджер (16.11.2026, владелец по
     * сделке 412 Совкомбанка; 18.11.2026 — выплат менеджеру нет вовсе), одной строкой, без зачёта.
     */
    public function test_prime_withheld_follows_payer(): void
    {
        $offer = $this->offer();
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer, $this->manager, 1050000), $this->admin, 30000, scheme: DealScheme::Prime);
        $this->pickedUp($offer);
        app(SaveDealContract::class)(DealContract::for($deal->fresh()), $this->buyerData(), $this->manager);
        $this->passportFor($deal->fresh()->contract->buyer);
        $this->assertEqualsWithDelta(1020000, $deal->fresh()->issuedInvoices()->sole()->remaining(), 0.01);
        $this->assertSame(1020000, (int) $deal->fresh()->contract->price);

        app(SaveDealContract::class)(DealContract::for($deal->fresh()), ['payer' => 'manager'], $this->manager);
        $sale = $deal->fresh()->issuedInvoices()->sole();
        $this->assertEqualsWithDelta(1020000, $sale->remaining(), 0.01);
        $this->assertSame([1020000.0], $sale->charges()->pluck('price')->map(fn ($p) => (float) $p)->all());
        $this->assertSame(0, $sale->payments()->where('source', PaymentSource::Offset)->count());
        $this->assertSame(1020000, (int) $deal->fresh()->contract->price);
    }
}
