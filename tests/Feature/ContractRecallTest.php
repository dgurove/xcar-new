<?php

namespace Tests\Feature;

use App\Billing\Invoice;
use App\Notifications\DocRecalledNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\EditContract;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\SaveDealContract;
use App\Offers\Actions\ShowToManager;
use App\Offers\ContractEdit;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * «Отозвать» счёт и договор и исправленный договор (19.11.2026, владелец: «ОЧЕНЬ не хватает возможности отозвать счёт и договор»,
 * «скачать docx, отредактировать и отправить менеджеру его»). Ошибки молчаливые и дорогие: менеджер платит по отозванному счёту,
 * получает не тот договор или отзыв ломает оплаченный счёт.
 */
class ContractRecallTest extends TestCase
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

    public function test_invoice_recall_hides_it_keeps_number_and_refuses_after_payment(): void
    {
        $deal = $this->deal();
        $invoice = $deal->issuedInvoices()->where('state', '!=', 'void')->sole();
        app(ShowToManager::class)->invoice($deal, $invoice, $this->admin);
        $number = $invoice->fresh()->number;
        $this->assertTrue($invoice->fresh()->isVisibleToManager($this->manager));
        $this->assertTrue(ShowToManager::canRecallInvoice($invoice->fresh()));

        // В строке счёта CRM — «Отозвать» вместо «Отправить».
        $this->actingAs($this->admin);
        $this->post($this->crm.'/work/deals/'.$deal->id.'/invoices/'.$invoice->id.'/recall')->assertRedirect();
        $recalled = $invoice->fresh();
        $this->assertNull($recalled->shown_at);
        $this->assertSame($number, $recalled->number);
        $this->assertFalse($recalled->isVisibleToManager($this->manager));
        $this->assertSame(0, Invoice::visibleToManager($this->manager)->count());
        Notification::assertSentTo($this->manager, DocRecalledNotice::class);

        // Отправили снова — тот же номер.
        app(ShowToManager::class)->invoice($deal->fresh(), $recalled, $this->admin);
        $this->assertSame($number, $invoice->fresh()->number);
        $this->assertTrue($invoice->fresh()->isVisibleToManager($this->manager));

        // Деньги пришли — отозвать нельзя.
        $invoice->fresh()->payments()->create(['party_id' => $invoice->party_id, 'amount' => 1000, 'paid_at' => now(), 'source' => 'bank', 'state' => 'confirmed', 'created_by' => $this->admin->id]);
        $invoice->fresh()->forceFill(['paid' => 1000])->save();
        $this->assertFalse(ShowToManager::canRecallInvoice($invoice->fresh()));
        $this->expectException(ValidationException::class);
        app(ShowToManager::class)->recallInvoice($deal->fresh(), $invoice->fresh(), $this->admin);
    }

    public function test_contract_recall_and_edited_contract_goes_to_manager(): void
    {
        $deal = $this->deal();
        $contract = DealContract::for($deal);
        $this->assertSame([], $contract->missing(), 'договор теста должен быть готов');
        $this->actingAs($this->admin);

        // Исправленный PDF-ом: менеджеру уйдёт он, Word у него нет; номер договора выдан при правке и не меняется отправкой.
        $this->assertTrue(EditContract::allows($deal));
        $this->post($this->crm.'/work/deals/'.$deal->id.'/contract/edited', ['file' => UploadedFile::fake()->createWithContent('dogovor.pdf', '%PDF-1.4 исправленный')])->assertRedirect();
        $deal = $deal->fresh();
        $this->assertTrue(ContractEdit::has($deal));
        $this->assertFalse(ContractEdit::hasWord($deal));
        $this->assertFalse(ContractEdit::stale($deal));
        $code = DealContract::for($deal)->code;
        $this->assertNotNull($code);
        $this->assertStringContainsString('исправленный', $this->get($this->crm.'/work/deals/'.$deal->id.'/dkp.pdf')->assertOk()->getContent());
        $this->get($this->crm.'/work/deals/'.$deal->id.'/dkp.docx')->assertNotFound();

        app(ShowToManager::class)->contract($deal, $this->admin);
        $this->assertSame($code, DealContract::for($deal->fresh())->code);
        $this->actingAs($this->manager);
        $this->assertStringContainsString('исправленный', $this->get(config('app.url').'/deals/'.$deal->id.'/dkp.pdf')->assertOk()->getContent());

        // Отправленный не правится и не возвращается к сформированному — сначала «Отозвать».
        $this->assertFalse(EditContract::allows($deal->fresh()));
        $this->actingAs($this->admin);
        $this->post($this->crm.'/work/deals/'.$deal->id.'/contract/recall')->assertRedirect();
        $this->assertNull(DealContract::for($deal->fresh())->shown_at);
        $this->assertSame($code, DealContract::for($deal->fresh())->code);
        Notification::assertSentTo($this->manager, DocRecalledNotice::class);
        $this->actingAs($this->manager);
        $this->get(config('app.url').'/deals/'.$deal->id.'/dkp.pdf')->assertNotFound();

        // «Вернуть сформированный» — снова из данных сделки.
        $this->actingAs($this->admin);
        $this->delete($this->crm.'/work/deals/'.$deal->id.'/contract/edited')->assertRedirect();
        $this->assertFalse(ContractEdit::has($deal->fresh()));
        $this->assertStringStartsWith('%PDF', $this->get($this->crm.'/work/deals/'.$deal->id.'/dkp.pdf')->assertOk()->getContent());

        // Приложен подписанный — отозвать отправленный нельзя.
        app(ShowToManager::class)->contract($deal->fresh(), $this->admin);
        $deal->fresh()->addMediaFromString('signed')->usingFileName('signed.pdf')->toMediaCollection('signed_contract');
        $this->assertFalse(ShowToManager::canRecallContract($deal->fresh()));
    }

    public function test_edited_contract_turns_stale_when_deal_data_changes(): void
    {
        $deal = $this->deal();
        $this->actingAs($this->admin);
        $this->post($this->crm.'/work/deals/'.$deal->id.'/contract/edited', ['file' => UploadedFile::fake()->createWithContent('dogovor.pdf', '%PDF-1.4')])->assertRedirect();
        $this->assertFalse(ContractEdit::stale($deal->fresh()));
        $deal->offer->car->update(['color' => 'Чёрный']);
        $this->assertTrue(ContractEdit::stale($deal->fresh()));
    }

    public function test_word_markup_keeps_borders_blanks_and_act_page(): void
    {
        $deal = $this->deal();
        $html = \App\Offers\DkpWord::html($deal);
        // Таблица ТС — рамкой, линии для ручки — подчёркиваниями, акт — с новой страницы, ни кнопки печати, ни «ОБРАЗЕЦ».
        $this->assertMatchesRegularExpression('/<table class="car"[^>]*border="1"/', $html);
        $this->assertStringNotContainsString('class="blank"', $html);
        $this->assertStringContainsString('____', $html);
        $this->assertStringContainsString('page-break-before: always', $html);
        $this->assertStringNotContainsString('Печать', $html);
        $this->assertStringNotContainsString('ОБРАЗЕЦ', $html);
        // Реквизиты с заголовком раздела — одной строкой таблицы (не разрываются ни в PDF, ни в Word).
        $this->assertMatchesRegularExpression('/<table class="keep-block"[^>]*>\s*<tr>\s*<td[^>]*>\s*<div class="sec">6\./u', $html);
    }
}
