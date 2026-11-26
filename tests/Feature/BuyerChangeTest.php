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
 * Сменить покупателя сделки (26.11.2026, владелец по 0311/046/03435/26: «когда прикрепили покупателя, его ни от лица менеджера,
 * ни в CRM невозможно убрать»; «тексты ошибок дебильно написаны»; «паспорт застрял в состоянии „распознаётся“»). Ошибки дорогие:
 * физлицо превращалось в организацию во всех своих сделках, договор уходил не на того, деньги — от одного, договор — на другого.
 */
class BuyerChangeTest extends TestCase
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

    public function test_manager_and_staff_switch_person_buyer_to_company(): void
    {
        $deal = $this->deal();
        $person = $deal->contract->buyer;
        $this->assertSame(PartyKind::Person, $person->party->kind);

        // Менеджер: плитка «Юр. лицо» при выбранном покупателе — черновик, «Сохранить» — новый покупатель-организация.
        $this->actingAs($this->manager)->post(self::SITE.'/deals/'.$deal->id.'/buyer-new', ['kind' => 'company'])->assertSessionHasNoErrors();
        $this->assertSame(PartyKind::Company, DealContract::for($deal->fresh())->draftParty->kind);
        $empty = $this->actingAs($this->manager)->put(self::SITE.'/deals/'.$deal->id.'/contract', ['buyer_id' => 'draft', 'buyer' => ['kind' => 'company']]);
        $empty->assertSessionHasErrors(['buyer.org' => 'Укажите название организации', 'buyer.inn' => 'Укажите ИНН']);
        $this->actingAs($this->manager)->put(self::SITE.'/deals/'.$deal->id.'/contract', ['buyer_id' => 'draft', 'buyer' => ['kind' => 'company',
            'org' => 'ООО «Ромашка»', 'inn' => '7707083893', 'director' => 'Иванов И. И.', 'legal_address' => 'г. Москва']])->assertSessionHasNoErrors();
        $company = DealContract::for($deal->fresh())->buyer;
        $this->assertNotSame($person->id, $company->id);
        $this->assertSame(PartyKind::Company, $company->party->kind);
        $this->assertSame($this->manager->id, $company->manager_id);
        // Прежний покупатель остался физлицом — у него свои сделки.
        $this->assertSame(PartyKind::Person, $person->fresh()->party->kind);

        // CRM: за менеджера — та же плитка, черновик и сохранение адресом CRM.
        $this->actingAs($this->admin)->post($this->crm.'/work/deals/'.$deal->id.'/buyer-new', ['kind' => 'person'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put($this->crm.'/work/deals/'.$deal->id.'/contract', ['buyer_id' => 'draft', 'buyer' => ['kind' => 'person',
            'name' => 'Петров Пётр Петрович']])->assertSessionHasNoErrors();
        $this->assertSame('Петров Пётр Петрович', DealContract::for($deal->fresh())->buyer->party->name);
    }

    public function test_signed_contract_locks_buyer(): void
    {
        $deal = $this->deal();
        $other = User::create(['name' => 'Другой', 'roles' => [Role::Buyer], 'manager_id' => $this->manager->id, 'approved_at' => now()]);
        $deal->forceFill(['contract_signed_at' => now()])->save();
        $this->assertSame('Договор подписан, покупателя не поменять', $deal->fresh()->buyerLocked());
        $this->actingAs($this->manager)->put(self::SITE.'/deals/'.$deal->id.'/contract', ['buyer_id' => (string) $other->id])
            ->assertSessionHasErrors(['buyer_id' => 'Договор подписан, покупателя не поменять']);
        $this->actingAs($this->manager)->post(self::SITE.'/deals/'.$deal->id.'/buyer-new', ['kind' => 'company'])->assertSessionHasErrors('buyer_id');
        $this->assertNotSame($other->id, DealContract::for($deal->fresh())->buyer_user_id);
    }

    public function test_stuck_passport_page_is_reread_then_failed(): void
    {
        Storage::fake('private');
        Queue::fake();
        $party = Party::create(['kind' => PartyKind::Person, 'name' => 'Аракелян Валерий Иванович']);
        $page = $party->addMediaFromString('scan')->usingFileName('p.webp')->withCustomProperties(['page' => 'reg', 'read' => 'reading'])->toMediaCollection('passport');
        $page->forceFill(['updated_at' => now()->subMinutes(20)])->saveQuietly();

        $this->assertSame(1, ReadPassport::retryStuck());
        Queue::assertPushed(ReadPassport::class, fn ($job) => $job->mediaId === $page->id);
        $this->assertSame(1, $page->fresh()->getCustomProperty('read_tries'));

        $page->fresh()->setCustomProperty('read_tries', 2)->save();
        \Illuminate\Support\Facades\DB::table('media')->where('id', $page->id)->update(['updated_at' => now()->subMinutes(20)]);
        ReadPassport::retryStuck();
        $this->assertSame('failed', $page->fresh()->getCustomProperty('read'));
        $this->assertNotSame('reading', $party->fresh()->scanState());
    }
}
