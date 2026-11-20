<?php

namespace Tests\Feature;

use App\Billing\Invoice;
use App\Notifications\ContractReadyNotice;
use App\Notifications\MoneyNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\SaveDealContract;
use App\Offers\Actions\ShowToManager;
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
 * Счёт и договор сделки (16.11.2026, владелец по сделке 412 Совкомбанка): счёт встаёт сразу с реквизитами, до шага оплаты — без
 * срока; менеджер видит счёт и договор только после «Отправить менеджеру» в CRM; на шаге оплаты счёт получает срок с тем же
 * номером. Ошибка молчаливая и дорогая: непроверенный счёт уходит менеджеру или проверенный не уходит вовсе.
 */
class DealInvoiceReleaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_is_formed_early_hidden_until_sent_and_gets_due_on_pay_step(): void
    {
        Storage::fake('private');
        Notification::fake();
        // Заход пишет в pgsql_async; в тесте это то же соединение, иначе транзакция теста строк не увидит.
        $this->app['db']->extend('pgsql_async', fn () => $this->app['db']->connection('pgsql'));
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Армен Хачян', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        // НДС поставщика 5 % — как ставка ПРАЙМ: счёт за ТС одной строкой с подбором (20.11.2026, `Deal::splitsSelection`; раздельные — `SupplierVatTest`).
        $offer = Offer::create(['vendor_id' => $vendor->id, 'supplier_vat' => 5, 'floor_price' => 944000, 'asking_price' => 1050000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $manager, 1050000), $admin, 30000, scheme: DealScheme::Prime);
        $this->assertSame('Забрать автомобиль', $offer->fresh()->stage()->name);

        // Покупатель с паспортом до «Автомобиль забрал» — счёт уже есть, без срока, менеджер его не видит.
        app(SaveDealContract::class)(DealContract::for($deal->fresh()), ['buyer_id' => 'new', 'new_buyer' => ['phone' => '+7 900 555-44-33'], 'buyer' => ['kind' => 'person',
            'name' => 'Файзулаев Комил', 'birth_at' => '1985-05-05', 'passport' => '11 11 111111', 'passport_issued' => 'ГУ МВД', 'passport_issued_at' => '2010-10-10', 'reg_address' => 'г. Москва']], $manager);
        $this->passportFor($deal->fresh()->contract->buyer);
        $invoice = $deal->fresh()->issuedInvoices()->where('state', '!=', 'void')->sole();
        $this->assertEqualsWithDelta(1020000, $invoice->total, 0.01);
        $this->assertNull($invoice->due_at);
        $this->assertNull($invoice->shown_at);
        // Номер — только при отправке: до неё «без номера», перевыставление номеров не съедает.
        $this->assertNull($invoice->number);
        $this->assertSame('без номера', $invoice->label());
        $this->assertFalse($invoice->isVisibleToManager($manager));
        $this->assertSame(0, Invoice::visibleToManager($manager)->count());
        $this->assertSame('Счёт готовится', \App\Billing\DealMoney::of($deal->fresh())->phrase);
        Notification::assertNotSentTo($manager, MoneyNotice::class);
        Notification::assertNotSentTo($manager, ContractReadyNotice::class);
        $this->assertFalse(DealContract::for($deal->fresh())->shownToManager());

        // Шаг оплаты наступил, счёт не отправлен — срок встаёт, номер тот же, ход наш: «Отправить счёт менеджеру».
        $exit = $offer->fresh()->position(Track::Sale)->stage->exits->firstWhere('label', 'Автомобиль забрал');
        app(TakeExit::class)($offer->fresh(), $exit, Actor::Manager, $manager);
        $due = $invoice->fresh();
        $this->assertSame($invoice->id, $deal->fresh()->issuedInvoices()->where('state', '!=', 'void')->sole()->id);
        $this->assertNotNull($due->due_at);
        $position = Position::where('offer_id', $offer->id)->where('track', Track::Sale)->with('stage')->firstOrFail();
        $this->assertTrue($position->stage->isPayStep());
        $this->assertSame(WaitsFor::Us, $position->waits_for);
        $this->assertSame('send', $deal->fresh()->invoiceGap());
        Notification::assertNotSentTo($manager, MoneyNotice::class);
        // В ходе сделки на шаге оплаты — строка «Менеджер не видит счёт» со шторкой отправки (`x-deal.send-task` в `x-route.path`).
        $this->actingAs($admin);
        $row = fn () => \Illuminate\Support\Facades\Blade::render('<x-deal.send-task :deal="$deal" :invoice="$invoice"/>', ['deal' => $deal->fresh(), 'invoice' => $invoice->fresh()]);
        // Что уходит — словами `Billing\PayOffer` (17.11.2026): шлюз в тесте не подключён — счёт, а не ссылка; «без номера» не пишем.
        $this->assertMatchesRegularExpression('/Менеджер не видит счёт за \S+\s*</u', $row());
        $this->assertStringContainsString('/work/deals/'.$deal->id.'/invoices/'.$invoice->id.'/show', $row());

        // «Отправить менеджеру» — видит, уведомление, ход его.
        app(ShowToManager::class)->invoice($deal->fresh(), $invoice, $admin);
        $this->assertSame(1, $invoice->fresh()->number);
        $this->assertTrue($invoice->fresh()->isVisibleToManager($manager));
        $this->assertStringContainsString('№ 1 у менеджера', $row());
        $this->assertStringNotContainsString('Менеджер не видит', $row());
        Notification::assertSentTo($manager, MoneyNotice::class);
        $this->assertNull($position->fresh()->waits_for);

        // Договор: готов (ПТС и собственник есть) — тоже только кнопкой.
        $contract = DealContract::for($deal->fresh());
        $this->assertFalse($contract->shownToManager());
        $contract->forceFill(['shown_at' => null])->save();
        if ($contract->isReady()) {
            app(ShowToManager::class)->contract($deal->fresh(), $admin);
            $this->assertTrue(DealContract::for($deal->fresh())->shownToManager());
            Notification::assertSentTo($manager, ContractReadyNotice::class);
        }
    }
}
