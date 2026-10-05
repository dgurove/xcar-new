<?php

namespace Tests\Feature;

use App\Billing\Actions\RecordPayment;
use App\Billing\ChargeKind;
use App\Billing\InvoiceState;
use App\Billing\PaymentSource;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\CancelDeal;
use App\Offers\Actions\PlaceBid;
use App\Offers\CommissionMode;
use App\Offers\DealScheme;
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
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Сделка «страхователю по ДКП» (05.10.2026, Т-Страхование, Ford Kuga): закупочная 779 000, собственнику 750 000
 * (взаимозачёт), цена 850 000, вознаграждение 20 000 — менеджер платит нам 80 000, счёт сразу при принятии; после ДКП
 * сделка ждёт оплату и закрывается ею, оплатил раньше — закрывается сразу; отменили — неоплаченный счёт гаснет. Ошибка
 * здесь — деньги: менеджер платит не ту сумму или сделка закрывается неоплаченной.
 */
class DkpDealFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private function deal(): array
    {
        Storage::fake('private');
        Notification::fake();
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Менеджер', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Т-Страхование тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::TBank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 779000, 'owner_price' => 750000, 'asking_price' => 850000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $this->manager, 850000), $this->admin, 20000, CommissionMode::Payout, scheme: DealScheme::OwnerDkp);

        return [$offer, $deal->fresh()];
    }

    /** Идём по маршруту исходами «вперёд», пока не встанем на этап с таким именем. */
    private function walkTo(Offer $offer, $deal, string $name): void
    {
        for ($i = 0; $i < 12 && ($o = $offer->fresh())->stage()->name !== $name; $i++) {
            $stage = $o->stage();
            $exit = $stage->exitsFor(Actor::Manager, $deal)->first(fn ($e) => $e->label !== 'Отказываюсь');
            $exit
                ? app(TakeExit::class)($o, $exit, Actor::Manager, $this->manager)
                : app(TakeExit::class)($o, $stage->exitsFor(Actor::Staff, $deal)->first(fn ($e) => ! str_contains(mb_strtolower($e->label), 'отказ') && ! str_contains(mb_strtolower($e->label), 'не поступила')), Actor::Staff, $this->admin);
        }
        $this->assertSame($name, $offer->fresh()->stage()->name);
    }

    public function test_selection_invoice_at_accept_and_deal_closes_on_payment(): void
    {
        [$offer, $deal] = $this->deal();

        $this->assertSame(779000, $deal->cost);
        $this->assertSame(750000, $deal->ownerPrice());
        $this->assertSame(29000, $deal->offset());
        $this->assertSame(CommissionMode::Withheld, $deal->commission_mode);
        $this->assertSame(80000, $deal->ours());
        $invoice = $deal->issuedInvoices()->sole();
        $this->assertSame(ChargeKind::Selection, $invoice->kind);
        // Одной строкой ровно то, что менеджер платит нам: без строки вознаграждения и зачёта.
        $this->assertEqualsWithDelta(80000, $invoice->total, 0.01);
        $this->assertEqualsWithDelta(80000, $invoice->remaining(), 0.01);
        $this->assertSame(1, $invoice->charges()->count());
        $this->assertFalse($invoice->isPartial());

        $this->walkTo($offer, $deal, 'Оплата подбора');
        app(RecordPayment::class)($invoice->fresh(), $this->admin, 80000, now(), PaymentSource::Bank);

        $this->assertSame(InvoiceState::Paid, $invoice->fresh()->state);
        $this->assertSame('Сделка закрыта', $offer->fresh()->stage()->name);
        $this->assertSame(DealState::Done, $deal->fresh()->state);
    }

    public function test_paid_before_contract_closes_right_after_it(): void
    {
        [$offer, $deal] = $this->deal();
        app(RecordPayment::class)($deal->issuedInvoices()->sole(), $this->admin, 80000, now(), PaymentSource::Bank);

        $this->walkTo($offer, $deal, 'Контакты владельца переданы менеджеру');
        $stage = $offer->fresh()->stage();
        app(TakeExit::class)($offer->fresh(), $stage->exitsFor(Actor::Manager, $deal)->firstWhere('label', 'Договор приложен'), Actor::Manager, $this->manager);

        $this->assertSame('Сделка закрыта', $offer->fresh()->stage()->name);
    }

    /** Ссылка при принятии — на менеджера и ровно 80 000; менеджер переделывает её на нового покупателя по ФИО. */
    public function test_link_is_80_and_manager_picks_new_buyer_as_payer(): void
    {
        config(['xcar.yookassa.shop_id' => '1', 'xcar.yookassa.secret' => 'test']);
        [, $deal] = $this->deal();
        $invoice = $deal->issuedInvoices()->sole();
        $this->assertEqualsWithDelta(80000, $invoice->openLink()->amount, 0.01);

        $this->actingAs($this->manager)->post('/account/money/deals/'.$deal->id.'/pay', ['invoice' => $invoice->id, 'way' => 'link', 'payer' => 'other', 'name' => 'Покупаев Пётр', 'phone' => '+7 900 111-22-33'])
            ->assertSessionHasNoErrors();

        $buyer = User::where('phone', '79001112233')->sole();
        $this->assertSame($this->manager->id, $buyer->manager_id);
        $this->assertTrue($buyer->isBuyer());
        $link = $invoice->fresh()->openLink();
        $this->assertSame($buyer->id, $link->payer_user_id);
        $this->assertEqualsWithDelta(80000, $link->amount, 0.01);
    }

    public function test_cancelled_deal_voids_unpaid_selection_invoice(): void
    {
        [, $deal] = $this->deal();
        app(CancelDeal::class)($deal, $this->admin);

        $this->assertSame(InvoiceState::Void, \App\Billing\Invoice::where('deal_id', $deal->id)->sole()->state);
    }
}
