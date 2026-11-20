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

    private function deal(DealScheme $scheme = DealScheme::Prime, string $format = 'commission'): Deal
    {
        Storage::fake('private');
        Notification::fake();
        config(['xcar.yookassa.shop_id' => '1', 'xcar.yookassa.secret' => 'test']);
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Иван Орлов', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => $format]);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        // НДС поставщика 5 % — как ставка ПРАЙМ: счёт за ТС одной строкой с подбором (20.11.2026, `Deal::splitsSelection`; раздельные — `SupplierVatTest`).
        $offer = Offer::create(['vendor_id' => $vendor->id, 'supplier_vat' => 5, 'floor_price' => 944000, 'asking_price' => 1050000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $this->manager, 1050000), $this->admin, 30000, scheme: $scheme,
            ownerPrice: $scheme === DealScheme::Insurer ? 750000 : null);
        $exit = $offer->fresh()->position(Track::Sale)->stage->exits->firstWhere('label', 'Автомобиль забрал');
        app(TakeExit::class)($offer->fresh(), $exit, Actor::Manager, $this->manager);
        $this->sendToManager($deal);

        return $deal->fresh();
    }

    /** ПРАЙМ, договор на менеджера, платит он — счёт одной строкой за вычетом вознаграждения. */
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
        // Одна строка за вычетом вознаграждения, кто бы ни платил (18.11.2026: вознаграждения мы не выплачиваем), договор — на
        // ту же сумму.
        $this->assertSame([1020000.0], $invoice->charges()->pluck('price')->map(fn ($p) => (float) $p)->all());
        $this->assertEqualsWithDelta(1020000, $invoice->remaining(), 0.01);

        // За ТС по ссылке не платят никогда (18.11.2026, владелец: «по ссылке ТОЛЬКО за подбор»): ни сама, ни руками.
        $this->sendToManager($deal);
        $invoice = $invoice->fresh();
        $this->assertNull(app(\App\Billing\Acquiring\Actions\EnsurePayLink::class)($invoice));
        try {
            app(\App\Billing\Acquiring\Actions\CreatePayLink::class)($invoice, $this->manager, 100000, \App\Billing\Acquiring\PayerKind::Other, null, $invoice->party->name, null, $invoice->party->email);
            $this->fail('ссылка за ТС заведена');
        } catch (\Illuminate\Validation\ValidationException) {
        }

        // Тот же ИНН второй раз — та же сторона, не дубль.
        $again = app(SetDealPayer::class)($deal->fresh(), $this->manager, ['payer' => 'new', 'kind' => 'company', 'name' => 'Ромашка', 'inn' => '7700000000']);
        $this->assertSame($party->id, $again->id);
        $this->assertSame(1, Party::where('inn', '7700000000')->count());

        // Обратно на менеджера — та же сумма одной строкой, ссылка прежнего плательщика погашена.
        app(SetDealPayer::class)($deal->fresh(), $this->manager, ['payer' => 'manager']);
        $back = $this->sale($deal);
        $this->assertSame($this->manager->fresh()->party_id, $back->party_id);
        $this->assertEqualsWithDelta(1020000, $back->remaining(), 0.01);
        // Выбор «менеджер» держится своей стороной (17.11.2026: по умолчанию подбор платит покупатель).
        $this->assertSame($this->manager->fresh()->party_id, $deal->fresh()->payer_party_id);
    }

    /**
     * У комиссии плательщика нет (18.11.2026, владелец: «плательщик указывается только для выставления договора счёта (ДКП)»;
     * «по ссылке просто почта»): сторона счёта — менеджер, у ссылки ни имени, ни почты; счёта-документа нет, пока его не
     * запросили («Запросить счёт…» — номер и PDF).
     */
    public function test_selection_has_no_payer_and_no_invoice_until_requested(): void
    {
        $deal = $this->deal(DealScheme::Insurer, 'supplier');
        $invoice = $deal->issuedInvoices()->sole();
        $this->assertSame($this->manager->fresh()->party_id, $invoice->party_id);
        $this->assertFalse($invoice->isDocument());
        $this->assertNull($invoice->number);
        $this->assertNull($invoice->openLink()?->payer_name);
        $this->assertNull($invoice->openLink()?->payer_email);

        $this->actingAs($this->manager)->post('/deals/'.$deal->id.'/invoices/'.$invoice->id.'/paper')->assertSessionHasNoErrors();
        $this->assertTrue($invoice->fresh()->isDocument());
        $this->assertNotNull($invoice->fresh()->number);
    }

    /** «Плательщик платит — ООО «Р.О.С.»» (16.11.2026): счёт подбора от Р.О.С., без ссылки; обратно ПРАЙМ — со ссылкой; оплатили — не меняется. */
    public function test_fee_seller_switches_selection_invoice(): void
    {
        $deal = $this->deal(DealScheme::Insurer, 'supplier');
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
