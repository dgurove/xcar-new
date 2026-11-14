<?php

namespace Tests\Feature;

use App\Billing\InvoiceState;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\SaveDealContract;
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
use Tests\TestCase;

/**
 * Счёт ПРАЙМ за ТС — по договору (14.11.2026, владелец): ставка НДС из блока «НДС» (`DealContract::vatRate`; сменилась
 * только ставка — тот же счёт, без новой нумерации), сумма договора руками (`SetContractPrice`) — счёт за ней. Модератору —
 * «Сделки» и сумма договора, остальное по сделке — нет. Ошибка здесь — не та сумма или ставка в счёте, лишний доступ.
 */
class ContractPriceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private function deal(): Deal
    {
        Storage::fake('private');
        Notification::fake();
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Тужиков Евгений', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 944000, 'asking_price' => 1050000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $this->manager, 1050000), $this->admin, 30000, CommissionMode::Payout, scheme: DealScheme::Prime);
        $exit = $offer->fresh()->position(Track::Sale)->stage->exits->firstWhere('label', 'Автомобиль забрал');
        app(TakeExit::class)($offer->fresh(), $exit, Actor::Manager, $this->manager);
        app(SaveDealContract::class)(DealContract::for($deal->fresh()), ['buyer_id' => 'new', 'new_buyer' => ['phone' => '+7 900 555-44-33'], 'buyer' => ['kind' => 'person',
            'name' => 'Покупаев Пётр Петрович', 'birth_at' => '1985-05-05', 'passport' => '11 11 111111', 'passport_issued' => 'ГУ МВД', 'passport_issued_at' => '2010-10-10', 'reg_address' => 'г. Москва']], $this->manager);
        $this->passportFor($deal->fresh()->contract->buyer);

        return $deal->fresh();
    }

    private function invoice(Deal $deal)
    {
        return $deal->fresh()->issuedInvoices()->where('state', '!=', InvoiceState::Void)->sole();
    }

    public function test_invoice_vat_follows_contract_and_changes_in_place(): void
    {
        $deal = $this->deal();
        // Цены без НДС — договор без НДС, и счёт тоже (было — ставка продавца 5 %).
        $first = $this->invoice($deal);
        $this->assertNull($first->vat_rate);
        $this->assertFalse($first->vat);

        app(SaveDealContract::class)(DealContract::for($deal), ['vat_rate' => 5], $this->admin);
        $same = $this->invoice($deal);
        $this->assertTrue($same->is($first), 'сменилась только ставка — тот же счёт');
        $this->assertSame(5, $same->vat_rate);
        $this->assertSame($first->number, $same->number);
    }

    public function test_manual_contract_price_drives_invoice_and_moderator_access(): void
    {
        $deal = $this->deal();
        $crm = 'http://'.config('xcar.crm_host');
        $moderator = User::create(['name' => 'Модератор', 'phone' => '79000000003', 'roles' => [Role::Moderator], 'approved_at' => now()]);

        $this->actingAs($moderator)->get($crm.'/work/deals')->assertOk();
        $this->actingAs($moderator)->put($crm.'/work/deals/'.$deal->id.'/contract-price', ['price' => '990 000'])->assertRedirect();
        $contract = $deal->fresh()->contract;
        $this->assertSame(990000, $contract->price);
        $this->assertTrue($contract->price_manual);
        $this->assertEqualsWithDelta(990000, (float) $this->invoice($deal)->total, 0.01);

        // Остальное по сделке модератору закрыто: схема, НДС, гараж.
        $this->actingAs($moderator)->post($crm.'/work/deals/'.$deal->id.'/scheme', ['scheme' => 'ros'])->assertNotFound();
        $this->actingAs($moderator)->put($crm.'/work/deals/'.$deal->id.'/contract', ['vat_rate' => 22])->assertForbidden();
        $this->actingAs($moderator)->get($crm.'/work/garage')->assertNotFound();

        // «Как в сделке» — снова по деньгам сделки.
        $this->actingAs($this->admin)->put($crm.'/work/deals/'.$deal->id.'/contract-price', ['auto' => 1])->assertRedirect();
        $this->assertFalse($deal->fresh()->contract->price_manual);
        $this->assertEqualsWithDelta(1050000, (float) $this->invoice($deal)->total, 0.01);
    }
}
