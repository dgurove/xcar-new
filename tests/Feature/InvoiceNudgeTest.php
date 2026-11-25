<?php

namespace Tests\Feature;

use App\Billing\Actions\NudgeInvoice;
use App\Chats\Message;
use App\Notifications\InvoiceNudgeNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\SaveDealContract;
use App\Offers\Actions\ShowToManager;
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
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * «Напомнить менеджеру» про отправленный счёт (19.11.2026, `NudgeInvoice`): сейчас — уведомление и сообщение в чате с номером,
 * датой и днями; «через N дней» — часы шлют в срок и снимают план; оплаченный и с платёжкой менеджера — не шлют. Ошибка дорогая:
 * менеджеру летит напоминание об оплаченном счёте или не летит вовсе.
 */
class InvoiceNudgeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private string $crm;

    private function deal(): Deal
    {
        Storage::fake('private');
        $this->crm = 'http://'.config('xcar.crm_host');
        Notification::fake();
        $this->app['db']->extend('pgsql_async', fn () => $this->app['db']->connection('pgsql'));
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Армен Хачян', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        // НДС поставщика 5 % — как ставка ПРАЙМ: счёт за ТС одной строкой с подбором (20.11.2026, `Deal::splitsSelection`).
        $offer = Offer::create(['vendor_id' => $vendor->id, 'supplier_vat' => 5, 'floor_price' => 944000, 'asking_price' => 1050000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->car->update(['vin' => 'LVTDB21B8RE049131', 'year' => 2024, 'pts' => '164302109662152', 'color' => 'Белый']);
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $this->manager, 1050000), $this->admin, 30000, scheme: DealScheme::Prime);
        app(SaveDealContract::class)(DealContract::for($deal->fresh()), ['buyer_id' => 'new', 'new_buyer' => ['phone' => '+7 900 555-44-33'], 'buyer' => ['kind' => 'person',
            'name' => 'Файзулаев Комил', 'birth_at' => '1985-05-05', 'passport' => '11 11 111111', 'passport_issued' => 'ГУ МВД', 'passport_issued_at' => '2010-10-10', 'reg_address' => 'г. Москва']], $this->manager);
        $this->passportFor($deal->fresh()->contract->buyer);
        // Собственник по договору комиссии — Совкомбанк: без него договор ПРАЙМ не готов.
        $owner = \App\Billing\Party::create(['kind' => 'company', 'name' => 'ПАО «Совкомбанк»', 'inn' => '4401116480']);
        DealContract::for($deal->fresh())->update(['seller_party_id' => $owner->id]);

        return $deal->fresh();
    }

    public function test_nudge_now_later_and_not_for_paid(): void
    {
        $deal = $this->deal();
        $invoice = $deal->issuedInvoices()->where('state', '!=', 'void')->sole();
        // Не отправлен — напоминать не о чем.
        $this->assertFalse(NudgeInvoice::allows($invoice));
        app(ShowToManager::class)->invoice($deal, $invoice, $this->admin);
        $invoice = $invoice->fresh();
        $this->assertTrue(NudgeInvoice::allows($invoice));

        // Сейчас: уведомление и сообщение в чате сделки с номером, датой и днями.
        $this->travel(3)->days();
        $this->actingAs($this->admin)->post($this->crm.'/work/deals/'.$deal->id.'/invoices/'.$invoice->id.'/nudge', ['when' => 'now'])->assertRedirect();
        Notification::assertSentTo($this->manager, InvoiceNudgeNotice::class);
        $text = NudgeInvoice::text($invoice->fresh());
        $this->assertStringContainsString('№ '.$invoice->fresh()->number, $text);
        $this->assertStringContainsString('прошло 3 дня', $text);
        $this->assertStringContainsString('документ об оплате', $text);
        $this->assertTrue(Message::where('text', 'like', 'Отправили счёт%')->exists());
        $this->assertNotNull($invoice->fresh()->nudged_at);

        // Через 2 дня: план стоит, до срока часы молчат, в срок шлют и снимают план.
        $this->post($this->crm.'/work/deals/'.$deal->id.'/invoices/'.$invoice->id.'/nudge', ['when' => 'days', 'days' => 2])->assertRedirect();
        $this->assertNotNull($invoice->fresh()->nudge_at);
        $this->assertSame(0, app(NudgeInvoice::class)->due());
        $this->travel(3)->days();
        $this->assertSame(1, app(NudgeInvoice::class)->due());
        $this->assertNull($invoice->fresh()->nudge_at);

        // «Не напоминать» снимает план.
        $this->post($this->crm.'/work/deals/'.$deal->id.'/invoices/'.$invoice->id.'/nudge', ['when' => 'days', 'days' => 1])->assertRedirect();
        $this->post($this->crm.'/work/deals/'.$deal->id.'/invoices/'.$invoice->id.'/nudge', ['when' => 'cancel'])->assertRedirect();
        $this->assertNull($invoice->fresh()->nudge_at);

        // Менеджер приложил платёжку — не напоминаем, запланированное гаснет молча.
        $this->post($this->crm.'/work/deals/'.$deal->id.'/invoices/'.$invoice->id.'/nudge', ['when' => 'days', 'days' => 1])->assertRedirect();
        $invoice->fresh()->claims()->create(['party_id' => $invoice->party_id, 'amount' => 1000, 'paid_at' => now(), 'source' => 'bank', 'state' => 'claimed', 'created_by' => $this->manager->id]);
        $this->assertFalse(NudgeInvoice::allows($invoice->fresh()));
        $this->travel(2)->days();
        $this->assertSame(0, app(NudgeInvoice::class)->due());
        $this->assertNull($invoice->fresh()->nudge_at);
    }

    public function test_blocks_show_captions_and_buttons(): void
    {
        $deal = $this->deal();
        $invoice = $deal->issuedInvoices()->where('state', '!=', 'void')->sole();
        $this->actingAs($this->admin);
        $money = fn () => \Illuminate\Support\Facades\Blade::render('<x-deal.money :deal="$deal"/>', ['deal' => $deal->fresh()]);
        // До отправки: подпись «Счёт — не отправлен», «Отправить менеджеру» под строкой.
        $html = $money();
        $this->assertMatchesRegularExpression('/<span>Счёт<\/span>\s*<span[^>]*text-danger[^>]*>не отправлен/u', $html);
        $this->assertStringContainsString('/invoices/'.$invoice->id.'/show', $html);
        // Отправлен: «Оплата получена» и «Напомнить менеджеру», «Отозвать» в строке.
        app(ShowToManager::class)->invoice($deal->fresh(), $invoice, $this->admin);
        $html = $money();
        $this->assertMatchesRegularExpression('/<span>Счёт<\/span>\s*<span[^>]*text-open[^>]*>отправлен/u', $html);
        $this->assertStringContainsString('Оплата получена', $html);
        $this->assertStringContainsString('Напомнить менеджеру', $html);
        $this->assertStringContainsString('/invoices/'.$invoice->id.'/recall', $html);
        $this->assertStringContainsString('<span>Договор</span>', $html);
    }
}
