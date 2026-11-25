<?php

namespace Tests\Feature;

use App\Billing\Actions\RecordPayment;
use App\Billing\ChargeKind;
use App\Billing\InvoiceState;
use App\Billing\PaymentSource;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\CancelDeal;
use App\Offers\Actions\PlaceBid;
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
 * (взаимозачёт), цена 850 000, вознаграждение 20 000 — менеджер платит нам 80 000, счёт без срока — как только
 * поставщик согласовал и менеджер сказал «Беру в работу» (09.10.2026: до того ни счёта, ни ссылки);
 * после ДКП сделка ждёт оплату (срок — с этого шага) и закрывается ею, оплатил раньше — закрывается сразу; отменили — неоплаченный счёт гаснет. Ошибка
 * здесь — деньги: менеджер платит не ту сумму или сделка закрывается неоплаченной.
 */
class DkpDealFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private function deal(bool $send = true): array
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
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $this->manager, 850000), $this->admin, 20000, scheme: DealScheme::OwnerDkp);
        // Пока поставщик не согласовал — ни счёта, ни ссылки. Сначала письмо поставщика, по нему согласие менеджера, потом наше
        // письмо (15.11.2026, `TBankConsentTest`); ответ с контактом — сразу контакты менеджеру.
        $this->assertFalse($deal->fresh()->issuedInvoices()->exists());
        $this->assertSame('Ждём письмо поставщика', $offer->fresh()->stage()->name);
        $this->walkTo($offer, $deal->fresh(), 'Связаться с владельцем');

        $send && $this->sendToManager($deal);

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

    public function test_selection_invoice_after_agreement_and_deal_closes_on_payment(): void
    {
        [$offer, $deal] = $this->deal();

        $this->assertSame(779000, $deal->cost);
        $this->assertSame(750000, $deal->ownerPrice());
        $this->assertSame(29000, $deal->offset());
        $this->assertSame(80000, $deal->ours());
        $invoice = $deal->issuedInvoices()->sole();
        $this->assertSame(ChargeKind::Selection, $invoice->kind);
        // Одной строкой ровно то, что менеджер платит нам: без строки вознаграждения и зачёта.
        $this->assertEqualsWithDelta(80000, $invoice->total, 0.01);
        $this->assertEqualsWithDelta(80000, $invoice->remaining(), 0.01);
        $this->assertSame(1, $invoice->charges()->count());
        $this->assertFalse($invoice->isPartial());
        // До договора с покупателем срока нет — он не должник (06.10.2026); срок ставит шаг оплаты.
        $this->assertNull($invoice->due_at);
        $this->assertFalse($invoice->isOverdue());

        $this->walkTo($offer, $deal, 'Оплата подбора');
        $this->assertNotNull($invoice->fresh()->due_at);
        app(RecordPayment::class)($invoice->fresh(), $this->admin, 80000, now(), PaymentSource::Bank);

        $this->assertSame(InvoiceState::Paid, $invoice->fresh()->state);
        $this->assertSame('Сделка закрыта', $offer->fresh()->stage()->name);
        $this->assertSame(DealState::Done, $deal->fresh()->state);
    }

    public function test_paid_before_contract_closes_right_after_it(): void
    {
        [$offer, $deal] = $this->deal();
        app(RecordPayment::class)($deal->issuedInvoices()->sole(), $this->admin, 80000, now(), PaymentSource::Bank);

        // Получение тремя шагами (06.10.2026): «Связался с владельцем» → «Автомобиль забрал» → «Договор приложен».
        $this->walkTo($offer, $deal, 'Подписанный договор');
        $stage = $offer->fresh()->stage();
        app(TakeExit::class)($offer->fresh(), $stage->exitsFor(Actor::Manager, $deal)->firstWhere('label', 'Договор приложен'), Actor::Manager, $this->manager);

        $this->assertSame('Сделка закрыта', $offer->fresh()->stage()->name);
    }

    /** Ссылка после согласования — ровно 80 000, без плательщика (18.11.2026): «Получить ссылку» с именем его не ставит. */
    public function test_link_is_80_and_follows_invoice_payer(): void
    {
        config(['xcar.yookassa.shop_id' => '1', 'xcar.yookassa.secret' => 'test']);
        [, $deal] = $this->deal();
        $invoice = $deal->issuedInvoices()->sole();
        $this->assertEqualsWithDelta(80000, $invoice->openLink()->amount, 0.01);

        $this->actingAs($this->manager)->post('/account/money/deals/'.$deal->id.'/pay', ['invoice' => $invoice->id, 'way' => 'link', 'payer' => 'other', 'name' => 'Покупаев Пётр', 'email' => 'petr@example.test'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, User::where('email', 'petr@example.test')->count());
        // Плательщика у комиссии нет (18.11.2026): ссылка без имени, почту введёт тот, кто платит.
        $this->assertNull($invoice->fresh()->openLink()->payer_name);
        $this->assertEqualsWithDelta(80000, $invoice->fresh()->openLink()->amount, 0.01);
    }

    /**
     * Взаимозачёт со страховой правится шторкой (23.11.2026, `DealController::ownerPrice`): собственнику 760 000 — взаимозачёт
     * 19 000, подбор 850 000 − 760 000 − 20 000 = 70 000 и счёт подбора на 70 000; больше закупочной — ошибка; «Без взаимозачёта» —
     * собственнику закупочная; по счёту платили — не правится.
     */
    public function test_owner_price_sets_offset_and_selection(): void
    {
        [, $deal] = $this->deal();
        $url = \App\Support\Surface::Crm->url('/work/deals/'.$deal->id.'/owner-price');
        $this->actingAs($this->admin)->put($url, ['owner_price' => '760 000'])->assertSessionHasNoErrors()->assertRedirect();
        $deal = $deal->fresh();
        $this->assertSame(19000, $deal->offset());
        $this->assertSame(70000, $deal->selectionDue());
        $this->assertEqualsWithDelta(70000, $deal->issuedInvoices()->where('kind', ChargeKind::Selection)->where('state', InvoiceState::Issued)->sole()->total, 0.01);

        $this->actingAs($this->admin)->put($url, ['owner_price' => '800 000'])->assertSessionHasErrors('owner_price');
        $this->actingAs($this->admin)->put($url, ['none' => 1])->assertSessionHasNoErrors();
        $this->assertSame(0, $deal->fresh()->offset());

        $invoice = $deal->fresh()->issuedInvoices()->where('kind', ChargeKind::Selection)->where('state', InvoiceState::Issued)->sole();
        app(RecordPayment::class)($invoice, $this->admin, 1000, now(), PaymentSource::Bank);
        $this->actingAs($this->admin)->put($url, ['owner_price' => '760 000'])->assertSessionHasErrors();
    }

    /**
     * «Редактировать можно всё» (24.11.2026, владелец): у «страховой напрямую» сумма поставщику правится той же шторкой, что у ДКП
     * собственнику (`owner_price`, взаимозачёт); закупочная в сделке — только у ПРАЙМ / Р.О.С. (`DealController::cost`), у схем с
     * подбором её дверь закрыта, после оплаты — ошибка, закупочная предложения прежняя.
     */
    public function test_supplier_sum_and_prime_cost_are_editable_in_crm(): void
    {
        [$offer, $deal] = $this->deal();
        $deal->forceFill(['scheme' => DealScheme::Insurer])->save();
        $crm = fn (string $p) => \App\Support\Surface::Crm->url('/work/deals/'.$deal->id.$p);
        $this->actingAs($this->admin)->put($crm('/owner-price'), ['owner_price' => '760 000'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(760000, $deal->fresh()->supplierPayment());
        $this->assertSame(19000, $deal->fresh()->offset());
        // Закупочная — полем шторки денег (25.11.2026): у «страховой напрямую» её там нет, присланное не трогается.
        $this->actingAs($this->admin)->put($crm('/money'), ['cost' => '700 000'])->assertRedirect();
        $this->assertNotSame(700000, $deal->fresh()->cost);

        $deal->forceFill(['scheme' => DealScheme::Prime, 'owner_price' => null])->save();
        $this->actingAs($this->admin)->put($crm('/money'), ['cost' => '900 000'])->assertSessionHasErrors('cost');
        $this->actingAs($this->admin)->put($crm('/money'), ['cost' => '770 000'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(770000, $deal->fresh()->cost);
        $this->assertSame(779000, (int) $offer->fresh()->floor_price);
        $invoice = $deal->fresh()->issuedInvoices()->where('state', InvoiceState::Issued)->first();
        if ($invoice) {
            app(RecordPayment::class)($invoice, $this->admin, 1000, now(), PaymentSource::Bank);
            $this->actingAs($this->admin)->put($crm('/money'), ['cost' => '760 000'])->assertSessionHasErrors();
            $this->assertSame(770000, $deal->fresh()->cost);
        }
    }

    public function test_cancelled_deal_voids_unpaid_selection_invoice(): void
    {
        [, $deal] = $this->deal();
        app(CancelDeal::class)($deal, $this->admin);

        $this->assertSame(InvoiceState::Void, \App\Billing\Invoice::where('deal_id', $deal->id)->sole()->state);
    }

    /**
     * Ссылка на оплату подбора — только вместе с «Отправить менеджеру» (17.11.2026, начальник: «пока не нажали „Отправить“,
     * ссылка не выставляется и не отображается»). До отправки её не заводит ни сама система, ни дозаполнение ссылок, ни
     * сотрудник из CRM, и в «Оплатах» такого счёта нет; отправили — ссылка на остаток и строка в «Нам должны».
     */
    public function test_link_only_after_invoice_is_sent_to_manager(): void
    {
        config(['xcar.yookassa.shop_id' => '1', 'xcar.yookassa.secret' => 'test']);
        [, $deal] = $this->deal(send: false);
        $invoice = $deal->issuedInvoices()->sole();
        $crm = 'http://'.config('xcar.crm_host');

        $this->assertNull($invoice->shown_at);
        $this->assertNull($invoice->openLink());
        $this->assertFalse(\App\Billing\Acquiring\PayLink::eligible($invoice));
        $this->artisan('acquiring:backfill-links')->assertSuccessful();
        $this->assertNull($invoice->fresh()->openLink());
        $this->actingAs($this->admin)->post($crm.'/work/money/invoices/'.$invoice->id.'/links', [])->assertSessionHasErrors('amount');
        $this->assertNull($invoice->fresh()->openLink());
        // «Оплаты» по менеджерам (22.11.2026, владелец: «с „Беру в работу“ можно выставлять ссылки»): неотправленный счёт взятой
        // сделки — делом «1 отправить» и до шага оплаты.
        $this->actingAs($this->admin)->get($crm.'/work/money')->assertOk()->assertSee('1 отправить');

        $this->sendToManager($deal);
        $this->assertEqualsWithDelta(80000, $invoice->fresh()->openLink()->amount, 0.01);
        $this->actingAs($this->admin)->get($crm.'/work/money')->assertOk()->assertDontSee('1 отправить');
    }
}
