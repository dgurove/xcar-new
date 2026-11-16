<?php

namespace Tests\Feature;

use App\Billing\Actions\RecordPayment;
use App\Billing\Acquiring\PayLinkState;
use App\Billing\InvoiceState;
use App\Billing\Party;
use App\Billing\PaymentSource;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\SaveDealContract;
use App\Offers\Actions\SetDealPayer;
use App\Offers\CommissionMode;
use App\Offers\Deal;
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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Плательщик сделки — один на счёт и ссылку (16.11.2026, владелец: «два способа оплаты одной суммы, а не два разных платежа»;
 * «у счёта тоже необязательно менеджер»). Ошибка молчаливая и дорогая: счёт не тому человеку, закупочная в счёте постороннему,
 * удержание вознаграждения из чужих денег, ссылка на одного, счёт на другого.
 */
class DealPayerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private function deal(CommissionMode $mode = CommissionMode::Withheld, DealScheme $scheme = DealScheme::Prime, string $format = 'commission'): Deal
    {
        Storage::fake('private');
        Notification::fake();
        config(['xcar.yookassa.shop_id' => '1', 'xcar.yookassa.secret' => 'test']);
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Иван Орлов', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => $format]);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 944000, 'asking_price' => 1050000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $this->manager, 1050000), $this->admin, 30000, $mode, scheme: $scheme,
            ownerPrice: $scheme === DealScheme::Insurer ? 750000 : null);
        $exit = $offer->fresh()->position(Track::Sale)->stage->exits->firstWhere('label', 'Автомобиль забрал');
        app(TakeExit::class)($offer->fresh(), $exit, Actor::Manager, $this->manager);
        $this->sendToManager($deal);

        return $deal->fresh();
    }

    /** ПРАЙМ, договор на менеджера, платит он — счёт с разбивкой и удержанием. */
    private function managerDeal(): Deal
    {
        $deal = $this->deal();
        app(SaveDealContract::class)(DealContract::for($deal), ['buyer_id' => 'me'], $this->manager);
        $this->passportFor($this->manager);

        return $deal->fresh();
    }

    private function sale(Deal $deal)
    {
        return $deal->fresh()->issuedInvoices()->where('state', '!=', InvoiceState::Void)->sole();
    }

    public function test_other_organization_gets_one_line_invoice_and_link_and_back_to_manager(): void
    {
        $deal = $this->managerDeal();
        $before = $this->sale($deal);
        $this->assertSame($this->manager->fresh()->party_id, $before->party_id);
        $this->assertEqualsWithDelta(1020000, $before->remaining(), 0.01);

        $party = app(SetDealPayer::class)($deal, $this->manager, ['payer' => 'new', 'kind' => 'company', 'name' => 'ООО Ромашка', 'inn' => '7700000000', 'email' => 'buh@romashka.ru']);
        $invoice = $this->sale($deal);
        $this->assertSame($party->id, $invoice->party_id);
        $this->assertSame(InvoiceState::Void, $before->fresh()->state);
        // Посторонний — одна строка (разбивка показала бы закупочную и долю менеджера) за вычетом вознаграждения: «удерживает
        // сам» — менеджер берёт его с покупателя (16.11.2026, владелец по сделке 412 Совкомбанка), договор — на ту же сумму.
        $this->assertSame([1020000.0], $invoice->charges()->pluck('price')->map(fn ($p) => (float) $p)->all());
        $this->assertEqualsWithDelta(1020000, $invoice->remaining(), 0.01);
        $this->assertTrue($deal->fresh()->withholds());
        $this->assertTrue($deal->fresh()->buyerPaysReward());

        // Ссылка (по счёту продажи её заводят руками) — на плательщика счёта.
        $link = app(\App\Billing\Acquiring\Actions\EnsurePayLink::class)($invoice) ?? app(\App\Billing\Acquiring\Actions\CreatePayLink::class)($invoice, $this->manager, 100000,
            \App\Billing\Acquiring\PayerKind::Other, null, $invoice->party->name, null, $invoice->party->email);
        $this->assertSame('ООО Ромашка', $link->payer_name);

        // Тот же ИНН второй раз — та же сторона, не дубль.
        $again = app(SetDealPayer::class)($deal->fresh(), $this->manager, ['payer' => 'new', 'kind' => 'company', 'name' => 'Ромашка', 'inn' => '7700000000']);
        $this->assertSame($party->id, $again->id);
        $this->assertSame(1, Party::where('inn', '7700000000')->count());

        // Обратно на менеджера — разбивка и удержание, ссылка прежнего плательщика погашена.
        app(SetDealPayer::class)($deal->fresh(), $this->manager, ['payer' => 'manager']);
        $back = $this->sale($deal);
        $this->assertSame($this->manager->fresh()->party_id, $back->party_id);
        $this->assertEqualsWithDelta(1020000, $back->remaining(), 0.01);
        $this->assertSame(PayLinkState::Canceled, $link->fresh()->state);
        $this->assertNull($deal->fresh()->payer_party_id);
    }

    public function test_selection_invoice_payer_follows_and_link_goes_to_him(): void
    {
        $deal = $this->deal(CommissionMode::Payout, DealScheme::Insurer, 'supplier');
        $invoice = $deal->issuedInvoices()->sole();
        $this->assertSame($this->manager->fresh()->party_id, $invoice->party_id);
        $buyer = User::create(['name' => 'Александр Видов', 'roles' => [Role::Buyer], 'manager_id' => $this->manager->id, 'approved_at' => now(), 'access' => []]);

        app(SetDealPayer::class)($deal, $this->manager, ['payer' => 'user:'.$buyer->id]);
        $this->sendToManager($deal);
        $now = $deal->fresh()->issuedInvoices()->where('state', '!=', InvoiceState::Void)->sole();
        $this->assertSame($buyer->fresh()->party_id, $now->party_id);
        $this->assertEqualsWithDelta($invoice->total, $now->total, 0.01);
        $this->assertSame('Александр Видов', $now->openLink()?->payer_name);
        $this->assertNull($invoice->fresh()->openLink());
    }

    public function test_link_on_someone_else_is_reissued_to_invoice_payer(): void
    {
        $deal = $this->deal(CommissionMode::Payout, DealScheme::Insurer, 'supplier');
        $invoice = $deal->issuedInvoices()->sole();
        // Как № 184 до 16.11.2026: счёт менеджеру, ссылку перевыпустили на его покупателя.
        $old = app(\App\Billing\Acquiring\Actions\CreatePayLink::class)($invoice, $this->manager, (float) $invoice->openLink()->amount, \App\Billing\Acquiring\PayerKind::Other, null, 'Александр Виеов');

        app(SetDealPayer::class)($deal, $this->admin, ['payer' => 'manager']);
        $this->assertSame(PayLinkState::Canceled, $old->fresh()->state);
        $this->assertSame('Иван Орлов', $invoice->fresh()->openLink()?->payer_name);
    }

    /** На странице оплаты вписали другое имя и заплатили — плательщик счёта и сделки он (16.11.2026). */
    public function test_name_typed_on_pay_page_becomes_payer(): void
    {
        $deal = $this->deal(CommissionMode::Payout, DealScheme::Insurer, 'supplier');
        $invoice = $deal->issuedInvoices()->sole();
        $link = $invoice->openLink();
        $link->update(['payer_name' => 'Сидоров Пётр', 'payer_email' => 'p@mail.ru']);
        app(RecordPayment::class)($invoice->fresh(), $this->admin, (float) $invoice->total, now(), PaymentSource::Acquiring);

        SetDealPayer::paidBy($invoice->fresh(), $link->fresh());
        $this->assertSame('Сидоров Пётр', $invoice->fresh()->party->name);
        $this->assertSame($invoice->fresh()->party_id, $deal->fresh()->payer_party_id);
        $this->assertSame('p@mail.ru', $invoice->fresh()->party->email);
    }

    /** «Плательщик платит — ООО «Р.О.С.»» (16.11.2026): счёт подбора от Р.О.С., без ссылки; обратно ПРАЙМ — со ссылкой; оплатили — не меняется. */
    public function test_fee_seller_switches_selection_invoice(): void
    {
        $deal = $this->deal(CommissionMode::Payout, DealScheme::Insurer, 'supplier');
        \App\Billing\Party::seller(\App\Billing\Seller::Ros)->forceFill(['inn' => '7700000001', 'bank_name' => 'Банк', 'account' => '40702810000000000001', 'bik' => '044525000'])->save();
        $first = $deal->issuedInvoices()->sole();
        $this->assertSame(\App\Billing\Seller::Prime, $first->seller);

        app(\App\Offers\Actions\SetFeeSeller::class)($deal, $this->admin, \App\Billing\Seller::Ros);
        $ros = $deal->fresh()->issuedInvoices()->where('state', '!=', InvoiceState::Void)->sole();
        $this->assertSame(\App\Billing\Seller::Ros, $ros->seller);
        $this->assertEqualsWithDelta($first->total, $ros->total, 0.01);
        $this->assertNull($ros->openLink());
        $this->assertNull($first->fresh()->openLink());

        app(\App\Offers\Actions\SetFeeSeller::class)($deal->fresh(), $this->admin, \App\Billing\Seller::Prime);
        $this->sendToManager($deal);
        $prime = $deal->fresh()->issuedInvoices()->where('state', '!=', InvoiceState::Void)->sole();
        $this->assertSame(\App\Billing\Seller::Prime, $prime->seller);
        $this->assertNotNull($prime->openLink());

        app(RecordPayment::class)($prime, $this->admin, 1000, now(), PaymentSource::Bank);
        $this->expectException(ValidationException::class);
        app(\App\Offers\Actions\SetFeeSeller::class)($deal->fresh(), $this->admin, \App\Billing\Seller::Ros);
    }

    public function test_paid_invoice_keeps_its_payer(): void
    {
        $deal = $this->managerDeal();
        app(RecordPayment::class)($this->sale($deal), $this->admin, 100000, now(), PaymentSource::Bank);

        $this->expectException(ValidationException::class);
        app(SetDealPayer::class)($deal->fresh(), $this->manager, ['payer' => 'new', 'kind' => 'person', 'name' => 'Пётр Покупаев']);
    }
}
