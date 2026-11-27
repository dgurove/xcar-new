<?php

namespace Tests\Feature;

use App\Billing\Jobs\ReadPassport;
use App\Billing\Party;
use App\Billing\PartyKind;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\SaveDealContract;
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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Реквизиты ИП и организации карточкой файлами (27.11.2026, менеджеры и сотрудники CRM: «просто приложить файлы с карточкой
 * организации»): с файлами поля не обязательны, вносим мы — «Проверить реквизиты» → «Заполнить из файлов».
 * Прежнее: сменить покупателя сделки (26.11.2026, владелец по 0311/046/03435/26: «когда прикрепили покупателя, его ни от лица менеджера,
 * ни в CRM невозможно убрать»; «тексты ошибок дебильно написаны»; «паспорт застрял в состоянии „распознаётся“»). Ошибки дорогие:
 * физлицо превращалось в организацию во всех своих сделках, договор уходил не на того, деньги — от одного, договор — на другого.
 */
class BuyerCardFilesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private string $crm;

    private const SITE = 'http://xcar.localhost';

    private function deal(): Deal
    {
        Storage::fake('private');
        Notification::fake();
        $this->crm = 'http://'.config('xcar.crm_host');
        $this->app['db']->extend('pgsql_async', fn () => $this->app['db']->connection('pgsql'));
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Дмитрий Макаров', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 944000, 'asking_price' => 1050000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $this->manager, 1050000), $this->admin, 30000, scheme: DealScheme::Prime);
        app(SaveDealContract::class)(DealContract::for($deal->fresh()), ['buyer_id' => 'new', 'new_buyer' => ['phone' => '+7 900 555-44-33'], 'buyer' => ['kind' => 'person',
            'name' => 'Аракелян Валерий Иванович', 'birth_at' => '1999-09-07', 'passport' => '4519 391936', 'passport_issued' => 'ГУ МВД', 'passport_issued_at' => '2019-10-01', 'reg_address' => 'г. Москва']], $this->manager);

        return $deal->fresh();
    }

    public function test_manager_attaches_card_files_and_staff_fills_them(): void
    {
        $deal = $this->deal();
        $this->actingAs($this->manager)->post(self::SITE.'/deals/'.$deal->id.'/buyer-new', ['kind' => 'company'])->assertSessionHasNoErrors();
        $draft = DealContract::for($deal->fresh())->draftParty;

        // Файлы — несколько, и Word тоже; ответ — блок с их строками.
        $this->actingAs($this->manager)->post(self::SITE.'/parties/'.$draft->id.'/card', ['key' => 'k', 'files' => [
            \Illuminate\Http\UploadedFile::fake()->createWithContent('карточка.pdf', "%PDF-1.4 карточка"),
            \Illuminate\Http\UploadedFile::fake()->create('реквизиты.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        ]])->assertOk()->assertSee('карточка');
        $this->assertCount(2, $draft->fresh()->getMedia('card'));

        // Без полей — сохраняется: реквизиты внесём мы.
        $this->actingAs($this->manager)->put(self::SITE.'/deals/'.$deal->id.'/contract', ['buyer_id' => 'draft', 'buyer' => ['kind' => 'company']])->assertSessionHasNoErrors();
        $deal = $deal->fresh();
        $party = $deal->contract->buyer->party;
        $this->assertSame($draft->id, $party->id);
        $this->assertTrue($party->cardOnly());
        $this->assertSame('files', $deal->invoiceGap());
        $this->assertFalse($deal->managerGap());

        // Во вкладке «Проверить реквизиты» — делом «Заполнить из файлов».
        $row = \App\Offers\DealTabs::rows(Deal::whereKey($deal->id)->with(\App\Offers\DealTabs::WITH)->get())->first();
        $this->assertSame('verify', $row['tab']);
        $this->assertSame('verify:files', $row['todo']['key']);

        // Сотрудник внёс — дело ушло.
        $this->actingAs($this->admin)->put($this->crm.'/work/deals/'.$deal->id.'/contract', ['buyer' => ['kind' => 'company',
            'org' => 'ООО «Ромашка»', 'inn' => '7707083893', 'director' => 'Иванов И. И.', 'legal_address' => 'г. Москва']])->assertSessionHasNoErrors();
        $this->assertFalse($party->fresh()->cardOnly());
        $row = \App\Offers\DealTabs::rows(Deal::whereKey($deal->id)->with(\App\Offers\DealTabs::WITH)->get())->first();
        $this->assertNotSame('verify:files', $row['todo']['key'] ?? null);

        // Без файлов поля по-прежнему обязательны.
        $this->actingAs($this->manager)->post(self::SITE.'/deals/'.$deal->id.'/buyer-new', ['kind' => 'company']);
        $this->actingAs($this->manager)->put(self::SITE.'/deals/'.$deal->id.'/contract', ['buyer_id' => 'draft', 'buyer' => ['kind' => 'company']])
            ->assertSessionHasErrors(['buyer.org' => 'Укажите название организации']);
    }
}
