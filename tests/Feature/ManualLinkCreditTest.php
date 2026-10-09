<?php

namespace Tests\Feature;

use App\Billing\Acquiring\LinkExists;
use App\Billing\Actions\AttachServiceCar;
use App\Billing\Actions\IssueServiceInvoice;
use App\Billing\Actions\RecordPayment;
use App\Billing\Actions\VoidPayment;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\PaymentSource;
use App\Billing\ServiceTitle;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\CommissionMode;
use App\Offers\Deal;
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
 * Разовая ссылка с машиной сделки засчитывается в сделку (09.10.2026, владелец; на проде Тужиков оплатил 53 000 ручной
 * ссылкой, а сделка назавтра выставила ему ещё 58 000). ДКП Т-Страхования: менеджер должен нам 80 000 по ссылке. Ошибка
 * здесь — деньги дважды: подбор на всю сумму после оплаты по ручной ссылке или две открытые ссылки на одну сделку.
 */
class ManualLinkCreditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private Offer $offer;

    private function accepted(): Deal
    {
        Storage::fake('private');
        Notification::fake();
        config(['xcar.yookassa.shop_id' => '1', 'xcar.yookassa.secret' => 'test']);
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Тужиков Евгений', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Т-Страхование тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::TBank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 779000, 'owner_price' => 750000, 'asking_price' => 850000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $this->offer = $offer->fresh();

        return app(AcceptBid::class)(app(PlaceBid::class)($this->offer, $this->manager, 850000), $this->admin, 20000, CommissionMode::Payout, scheme: DealScheme::OwnerDkp)->fresh();
    }

    private function walkTo(Deal $deal, string $name): void
    {
        for ($i = 0; $i < 14 && ($o = $this->offer->fresh())->stage()->name !== $name; $i++) {
            $stage = $o->stage();
            $exit = $stage->exitsFor(Actor::Manager, $deal)->first(fn ($e) => $e->label !== 'Отказываюсь');
            $exit
                ? app(TakeExit::class)($o, $exit, Actor::Manager, $this->manager)
                : app(TakeExit::class)($o, $stage->exitsFor(Actor::Staff, $deal)->first(fn ($e) => ! str_contains(mb_strtolower($e->label), 'отказ') && ! str_contains(mb_strtolower($e->label), 'не поступила')), Actor::Staff, $this->admin);
        }
        $this->assertSame($name, $this->offer->fresh()->stage()->name);
    }

    private function manual(float $amount, ?Offer $offer = null, string $name = 'Тужиков Евгений Сергеевич'): Invoice
    {
        return app(IssueServiceInvoice::class)(['name' => $name], $this->admin, $amount, ServiceTitle::DEFAULT, $offer);
    }

    private function selection(Deal $deal): ?Invoice
    {
        return $deal->fresh()->issuedInvoices()->where('kind', ChargeKind::Selection)->first();
    }

    public function test_paid_manual_link_reduces_selection(): void
    {
        $deal = $this->accepted();
        $manual = $this->manual(50000, $this->offer);
        $this->assertSame($deal->id, $manual->credit_deal_id);
        $this->assertNull($manual->deal_id);
        $this->assertTrue($manual->isService());
        app(RecordPayment::class)($manual->fresh(), $this->admin, 50000, now(), PaymentSource::Bank);

        $this->walkTo($deal, 'Подтвердили покупку поставщику');

        $this->assertEqualsWithDelta(30000, $this->selection($deal)->total, 0.01);
        $this->assertEqualsWithDelta(30000, $this->selection($deal)->openLink()->amount, 0.01);
    }

    public function test_covered_selection_is_not_issued_and_pay_step_passes(): void
    {
        $deal = $this->accepted();
        app(RecordPayment::class)($this->manual(80000, $this->offer)->fresh(), $this->admin, 80000, now(), PaymentSource::Bank);

        $this->walkTo($deal, 'Подписанный договор');
        $this->assertNull($this->selection($deal));
        $this->assertTrue($deal->fresh()->coveredByCredit());
        $this->assertTrue($deal->fresh()->hasManagerInvoice());

        $stage = $this->offer->fresh()->stage();
        app(TakeExit::class)($this->offer->fresh(), $stage->exitsFor(Actor::Manager, $deal)->firstWhere('label', 'Договор приложен'), Actor::Manager, $this->manager);
        $this->assertSame('Сделка закрыта', $this->offer->fresh()->stage()->name);
    }

    public function test_refund_brings_selection_back(): void
    {
        $deal = $this->accepted();
        $manual = $this->manual(50000, $this->offer);
        $payment = app(RecordPayment::class)($manual->fresh(), $this->admin, 50000, now(), PaymentSource::Bank);
        $this->walkTo($deal, 'Подтвердили покупку поставщику');
        $this->assertEqualsWithDelta(30000, $this->selection($deal)->total, 0.01);

        app(VoidPayment::class)($payment, $this->admin, 'возврат');

        $this->assertEqualsWithDelta(80000, $this->selection($deal)->total, 0.01);
    }

    public function test_open_manual_link_retires_when_deal_issues_its_own(): void
    {
        $deal = $this->accepted();
        $manual = $this->manual(80000, $this->offer);
        $this->assertNotNull($manual->openLink());

        $this->walkTo($deal, 'Подтвердили покупку поставщику');

        $this->assertSame(InvoiceState::Void, $manual->fresh()->state);
        $this->assertNull($manual->fresh()->openLink());
        $this->assertEqualsWithDelta(80000, $this->selection($deal)->total, 0.01);
        $this->assertNotNull($this->selection($deal)->openLink());
    }

    public function test_manual_link_taken_before_deal_is_adopted(): void
    {
        $deal = $this->accepted();
        // Ссылка по этой машине, взятая до сделки: машина записана, сделки у неё не было.
        $manual = $this->manual(50000);
        $manual->update(['offer_id' => $this->offer->id, 'credit_deal_id' => null]);
        app(RecordPayment::class)($manual->fresh(), $this->admin, 50000, now(), PaymentSource::Bank);

        $this->walkTo($deal, 'Подтвердили покупку поставщику');

        $this->assertSame($deal->id, $manual->fresh()->credit_deal_id);
        $this->assertEqualsWithDelta(30000, $this->selection($deal)->total, 0.01);
    }

    public function test_second_link_for_same_deal_or_payer_is_refused(): void
    {
        $deal = $this->accepted();
        $this->walkTo($deal, 'Подтвердили покупку поставщику');
        $own = $this->selection($deal)->openLink();

        try {
            $this->manual(10000, $this->offer, 'Кто-то другой');
            $this->fail('вторая ссылка по сделке');
        } catch (LinkExists $e) {
            $this->assertSame($own->id, $e->link->id);
        }

        $first = $this->manual(10000, null, 'Морковин Дмитрий Евгеньевич');
        $this->actingAs($this->admin)->post('http://'.config('xcar.crm_host').'/work/money/service', ['amount' => '20 000', 'title' => ServiceTitle::DEFAULT, 'party_id' => 'new', 'party_name' => 'морковин  дмитрий евгеньевич'])
            ->assertRedirect('/work/money?tab=links&peek='.$first->openLink()->id);
        $this->assertSame(1, Invoice::where('kind', ChargeKind::Service)->where('state', InvoiceState::Issued)->count());
    }

    public function test_attaching_paid_manual_link_later_reduces_unpaid_selection(): void
    {
        $deal = $this->accepted();
        $manual = $this->manual(53000);
        app(RecordPayment::class)($manual->fresh(), $this->admin, 53000, now(), PaymentSource::Bank);
        $this->walkTo($deal, 'Подтвердили покупку поставщику');
        $this->assertEqualsWithDelta(80000, $this->selection($deal)->total, 0.01);

        app(AttachServiceCar::class)($manual->fresh(), $this->offer, null, $this->admin);

        $this->assertSame($deal->id, $manual->fresh()->credit_deal_id);
        $this->assertEqualsWithDelta(27000, $this->selection($deal)->total, 0.01);
        $this->assertSame(1, $deal->fresh()->issuedInvoices()->where('kind', ChargeKind::Selection)->count());
    }

    public function test_share_text_names_payer_car_and_amount(): void
    {
        $deal = $this->accepted();
        $this->walkTo($deal, 'Подтвердили покупку поставщику');
        $link = $this->selection($deal)->openLink();

        $lines = explode("\n", $link->message());
        $this->assertSame('Тужиков Евгений', $lines[0]);
        $this->assertStringStartsWith('ТС', $lines[1]);
        $this->assertSame("80\u{00A0}000\u{00A0}₽", $lines[2]);
        $this->assertSame('Оплата картой, СБП или SberPay по ссылке:', $lines[3]);
    }

    /** «По ссылкам» — ссылки сделок и ручные одним списком, ТС у строки, отменённые только чипом, старый адрес счёта жив. */
    public function test_links_list_shows_deal_and_manual_links(): void
    {
        $deal = $this->accepted();
        $this->walkTo($deal, 'Подтвердили покупку поставщику');
        $manual = $this->manual(10000, null, 'Морковин  Дмитрий Евгеньевич');
        $manual->update(['car_title' => 'Lada Vesta']);
        $crm = 'http://'.config('xcar.crm_host');
        $this->actingAs($this->admin);

        $this->get($crm.'/work/money')->assertOk()
            ->assertSee('data-detail-key="'.$manual->openLink()->id.'"', false)->assertSee('data-detail-key="'.$this->selection($deal)->openLink()->id.'"', false)
            ->assertSee('Тужиков Евгений')->assertSee('Сделка')
            ->assertSee('Морковин Дмитрий Евгеньевич')->assertSee('Lada Vesta')->assertSee('Вручную');
        $this->get($crm.'/work/money?q='.urlencode('морковин дмитрий'))->assertOk()->assertSee('data-detail-key="'.$manual->openLink()->id.'"', false)->assertDontSee('data-detail-key="'.$this->selection($deal)->openLink()->id.'"', false);

        $link = $manual->openLink();
        $this->get($crm.'/work/money?peek='.$manual->id, ['Turbo-Frame' => 'detail'])->assertOk()->assertSee('Морковин Дмитрий Евгеньевич')->assertSee('Сохранить ТС');
        $this->delete($crm.'/work/money/links/'.$link->id)->assertRedirect();
        $this->assertSame(InvoiceState::Void, $manual->fresh()->state);

        $row = 'data-detail-key="'.$link->id.'"';
        $this->get($crm.'/work/money')->assertDontSee($row, false);
        $this->get($crm.'/work/money?canceled=1')->assertSee($row, false);
    }
}
