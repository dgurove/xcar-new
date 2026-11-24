<?php

namespace Tests\Feature;

use App\Billing\InvoiceState;
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
 * только ставка — тот же счёт, без новой нумерации). Суммы договора руками нет с 18.11.2026: договор и счёт — цена продажи минус
 * вознаграждение и часть наличными мимо счёта (`cash_part`), правит и модератор. Ошибка здесь — не та сумма или ставка в счёте.
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
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $this->manager, 1050000), $this->admin, 30000, scheme: DealScheme::Prime);
        $exit = $offer->fresh()->position(Track::Sale)->stage->exits->firstWhere('label', 'Автомобиль забрал');
        app(TakeExit::class)($offer->fresh(), $exit, Actor::Manager, $this->manager);
        app(SaveDealContract::class)(DealContract::for($deal->fresh()), ['buyer_id' => 'new', 'new_buyer' => ['phone' => '+7 900 555-44-33'], 'buyer' => ['kind' => 'person',
            'name' => 'Покупаев Пётр Петрович', 'birth_at' => '1985-05-05', 'passport' => '11 11 111111', 'passport_issued' => 'ГУ МВД', 'passport_issued_at' => '2010-10-10', 'reg_address' => 'г. Москва']], $this->manager);
        $this->passportFor($deal->fresh()->contract->buyer);

        return $deal->fresh();
    }

    private function invoice(Deal $deal)
    {
        return $deal->fresh()->issuedInvoices()->where('kind', \App\Billing\ChargeKind::Sale)->where('state', '!=', InvoiceState::Void)->sole();
    }

    public function test_invoice_vat_follows_contract_and_changes_in_place(): void
    {
        $deal = $this->deal();
        // Цены без НДС — договор без НДС, и счёт тоже (было — ставка продавца 5 %).
        $first = $this->invoice($deal);
        $this->assertNull($first->vat_rate);
        $this->assertFalse($first->vat);

        // 22 % — сумма счёта за ТС та же: подбор в нём, отдельно — только выбором (24.11.2026, `Deal::splitsSelection`).
        app(SaveDealContract::class)(DealContract::for($deal), ['vat_rate' => 22], $this->admin);
        $same = $this->invoice($deal);
        $this->assertTrue($same->is($first), 'сменилась только ставка — тот же счёт');
        $this->assertSame(22, $same->vat_rate);
        $this->assertSame($first->number, $same->number);
    }

    public function test_cash_part_lowers_contract_and_invoice_and_moderator_access(): void
    {
        $deal = $this->deal();
        $crm = 'http://'.config('xcar.crm_host');
        $moderator = User::create(['name' => 'Модератор', 'phone' => '79000000003', 'roles' => [Role::Moderator], 'approved_at' => now()]);

        $this->actingAs($moderator)->get($crm.'/work/deals')->assertRedirect();
        $this->actingAs($moderator)->get($crm.'/offers?preset=agree')->assertOk();
        // Ставка ПРАЙМ 5 % — одна строка «ТС» на цену минус вознаграждение (подбор не отдельно, 20.11.2026).
        app(SaveDealContract::class)(DealContract::for($deal), ['vat_rate' => 5], $this->admin);
        // Часть отдали наличными мимо счёта — договор и счёт меньше на неё, цена продажи та же (18.11.2026).
        $this->actingAs($moderator)->put($crm.'/work/deals/'.$deal->id.'/money', ['amount' => '1 050 000', 'commission' => 30000, 'cash_part' => '30 000'])->assertRedirect();
        $contract = $deal->fresh()->contract;
        $this->assertSame(30000, $contract->cash_part);
        $this->assertSame(990000, $contract->price);
        $this->assertSame(1050000, (int) $deal->fresh()->amount);
        $this->assertEqualsWithDelta(990000, (float) $this->invoice($deal)->total, 0.01);

        // Схему («Кому платит покупатель», через Р.О.С.) модератор меняет с 17.11.2026, ставку НДС договора — с 18.11.2026 (владелец:
        // «полный доступ ко всему, что касается договоров и счетов»). Гараж с 17.11.2026 открыт — вкладкой «Предложений».
        $this->actingAs($moderator)->post($crm.'/work/deals/'.$deal->id.'/scheme', ['scheme' => 'ros', 'scheme_ok' => 1])->assertRedirect(); // дверь открыта (у этого вендора Р.О.С. нет — ошибкой поля, не 404)
        $this->actingAs($moderator)->put($crm.'/work/deals/'.$deal->id.'/contract', ['vat_rate' => 22])->assertRedirect();
        $this->assertSame(22, $deal->fresh()->contract->vat_rate);
        $this->actingAs($moderator)->get($crm.'/work/garage')->assertRedirect();

        // Наличные стёрли — снова цена минус вознаграждение.
        $this->actingAs($this->admin)->put($crm.'/work/deals/'.$deal->id.'/money', ['amount' => '1 050 000', 'commission' => 30000, 'cash_part' => ''])->assertRedirect();
        $this->assertNull($deal->fresh()->contract->cash_part);
        // Ставка 22 % — подбор всё равно в счёте за ТС (24.11.2026); отдельной ссылкой — выбором в той же шторке, и модератору.
        $this->assertEqualsWithDelta(1020000, (float) $this->invoice($deal)->total, 0.01);
        $this->actingAs($moderator)->put($crm.'/work/deals/'.$deal->id.'/money', ['amount' => '1 050 000', 'commission' => 30000, 'selection_apart' => '1'])->assertRedirect();
        $this->assertEqualsWithDelta(944000, (float) $this->invoice($deal)->total, 0.01);
        $this->assertEqualsWithDelta(76000, (float) $deal->fresh()->issuedInvoices()->where('kind', \App\Billing\ChargeKind::Selection)->where('state', '!=', InvoiceState::Void)->sole()->total, 0.01);
    }
}
