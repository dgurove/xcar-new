<?php

namespace Tests\Feature;

use App\Billing\Actions\RecordPayment;
use App\Billing\Party;
use App\Billing\PaymentSource;
use App\Billing\Seller;
use App\Notifications\InvoiceNeededNotice;
use App\Notifications\MoneyNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\SaveDealContract;
use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealScheme;
use App\Offers\Handover;
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
use App\Workflow\WaitsFor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * АльфаСтрахование (10.10.2026, владелец): забирать — только после оплаты; с НДС покупатель платит ООО «Р.О.С.», оно
 * переводит Альфе — и это нельзя пропустить. Ошибка здесь — машину забрали без денег или деньги Альфы зависли на Р.О.С.
 */
class AlfaPrepayTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private function offer(bool $vat): Offer
    {
        Storage::fake('private');
        Notification::fake();
        config(['xcar.yookassa.shop_id' => '1', 'xcar.yookassa.secret' => 'test']);
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Менеджер Тест', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        $vendor->forceFill(['pay_before_pickup' => true])->save();
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1200000, 'prices_include_vat' => $vat, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());

        return $offer->fresh();
    }

    private function accept(Offer $offer): Deal
    {
        return app(AcceptBid::class)(app(PlaceBid::class)($offer, $this->manager, 1200000), $this->admin, 50000, CommissionMode::Payout,
            scheme: DealScheme::forOffer($offer), ownerPrice: 900000);
    }

    /** До этапа оплаты: согласие поставщика, «Беру в работу», подтверждение. */
    private function toPayStep(Offer $offer, Deal $deal): void
    {
        for ($i = 0; $i < 10 && ! ($o = $offer->fresh())->stage()->isPayStep(); $i++) {
            $stage = $o->stage();
            $exit = $stage->exitsFor(Actor::Manager, $deal)->first(fn ($e) => $e->label === 'Беру в работу');
            $exit
                ? app(TakeExit::class)($o, $exit, Actor::Manager, $this->manager)
                : app(TakeExit::class)($o, $stage->exitsFor(Actor::Staff, $deal)->first(fn ($e) => ! str_contains(mb_strtolower($e->label), 'отказ')), Actor::Staff, $this->admin);
        }
        $this->assertTrue($offer->fresh()->stage()->isPayStep());
    }

    private function exit(Offer $offer, string $label): void
    {
        $o = $offer->fresh();
        $deal = $o->deal()->first();
        $exit = $o->stage()->exits->first(fn ($e) => $e->label === $label && $e->fits($deal));
        $this->assertNotNull($exit, 'на «'.$o->stage()->name.'» нет «'.$label.'»');
        app(TakeExit::class)($o, $exit, $exit->actor, $exit->actor === Actor::Manager ? $this->manager : $this->admin);
    }

    public function test_with_vat_goes_through_ros_and_pickup_waits_for_payment(): void
    {
        $offer = $this->offer(vat: true);
        $deal = $this->accept($offer);
        $this->assertSame(DealScheme::Ros, $deal->scheme);
        $this->toPayStep($offer, $deal);
        $this->assertFalse($deal->fresh()->mayPickUp());
        $this->assertFalse(Handover::for($deal->fresh())->open);

        // Покупатель указан, а реквизитов Р.О.С. нет — счёта нет, ход наш «Заполните реквизиты Р.О.С.».
        app(SaveDealContract::class)(DealContract::for($deal->fresh()), ['buyer_id' => 'me'], $this->manager);
        $this->assertSame('ros_requisites', $deal->fresh()->invoiceGap());
        $this->assertSame(WaitsFor::Us, $offer->fresh()->position(Track::Sale)->waits_for);
        Notification::assertSentTo($this->admin, InvoiceNeededNotice::class);

        // Реквизиты заполнили — счёт от Р.О.С., своей нумерацией, без ссылки.
        Party::seller(Seller::Ros)->update(['inn' => '7700000000', 'bank_name' => 'Банк', 'bik' => '044525000', 'account' => '40702810000000000001', 'corr_account' => '30101810000000000000']);
        // Дальше ход менеджера: «Я сам» — физлицо, без паспорта со сканом счёта нет (11.10.2026).
        app(\App\Offers\Actions\SyncDealInvoices::class)($deal->fresh(), $this->admin);
        $this->assertSame('passport', $deal->fresh()->invoiceGap());
        $this->passportFor($this->manager);
        $invoice = $deal->fresh()->issuedInvoices()->sole();
        $this->assertSame(Seller::Ros, $invoice->seller);
        $this->assertSame(1, $invoice->number);
        $this->assertNull($invoice->openLink());

        // «Поступило» на Р.О.С. — громко сотрудникам, сделка на нашем переводе Альфе, забирать всё ещё рано.
        app(RecordPayment::class)($invoice->fresh(), $this->admin, (float) $invoice->total, now(), PaymentSource::Bank);
        Notification::assertSentTo($this->admin, MoneyNotice::class, fn (MoneyNotice $n) => str_contains($n->title(), 'с Р.О.С.') && $n->important());
        $this->assertSame('Перевод поставщику с Р.О.С.', $offer->fresh()->stage()->name);
        $this->assertFalse($deal->fresh()->mayPickUp());

        $this->exit($offer, 'Переведено поставщику');
        $this->assertSame('Подтверждение оплаты поставщиком', $offer->fresh()->stage()->name);
        $this->exit($offer, 'Оплата получена');
        $this->assertNotNull($deal->fresh()->prepaid_at);
        $this->assertTrue($deal->fresh()->mayPickUp());
    }

    public function test_without_vat_buyer_pays_alfa_directly(): void
    {
        $offer = $this->offer(vat: false);
        $deal = $this->accept($offer);
        $this->assertSame(DealScheme::Insurer, $deal->scheme);
        $this->toPayStep($offer, $deal);

        // Подбор — ПРАЙМ со ссылкой; покупатель платит Альфе сам, перевода с Р.О.С. нет.
        $selection = $deal->fresh()->issuedInvoices()->sole();
        $this->assertSame(Seller::Prime, $selection->seller);
        $this->assertNotNull($selection->openLink());
        $this->assertFalse($deal->fresh()->mayPickUp());

        $this->exit($offer, 'Платёжное поручение приложено');
        $this->assertSame('Подтверждение оплаты поставщиком', $offer->fresh()->stage()->name);
        $this->exit($offer, 'Оплата получена');
        $this->assertTrue($deal->fresh()->mayPickUp());
    }
}
