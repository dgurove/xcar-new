<?php

namespace Tests\Feature;

use App\Billing\Party;
use App\Billing\PartyKind;
use App\Billing\PartyRules;
use App\Billing\Seller;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\ChangeDealScheme;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\UpdateOffer;
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
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * «Через Р.О.С.» (17.11.2026, владелец: «машина от Альфы с НДС — платёж от ООО «Р.О.С.», покупатель платит Р.О.С.; как это
 * указать в CRM — не понял»): схема идёт за «С НДС» сама, наше юрлицо покупателем не вписать, вписанное снимается переводом.
 */
class RosSchemeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private function deal(bool $vat = false): Deal
    {
        Notification::fake();
        Queue::fake();
        config(['xcar.mercure.hub' => '']);
        $this->admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Роман Корабельников', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'АльфаСтрахование тест']);
        $vendor->forceFill(['pay_before_pickup' => true])->save();
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => '5491/046/00861/26', 'floor_price' => 900000, 'asking_price' => 1100000,
            'prices_include_vat' => $vat, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());

        return app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $manager, 1150000), $this->admin, scheme: DealScheme::Insurer)->fresh();
    }

    public function test_scheme_follows_vat(): void
    {
        $deal = $this->deal();
        $this->assertSame(DealScheme::Insurer, $deal->schemeOf());

        app(UpdateOffer::class)($deal->offer->fresh(), ['prices_include_vat' => true], $this->admin);
        $this->assertSame(DealScheme::Ros, $deal->fresh()->schemeOf(), 'с НДС — через Р.О.С.');

        app(UpdateOffer::class)($deal->offer->fresh(), ['prices_include_vat' => false], $this->admin);
        $this->assertSame(DealScheme::Insurer, $deal->fresh()->schemeOf(), 'без НДС — снова напрямую');
    }

    public function test_our_company_is_not_a_buyer(): void
    {
        $inn = (string) Seller::Ros->config()['inn'];
        $rules = PartyRules::passport('buyer');
        $v = Validator::make(['buyer' => ['kind' => 'company', 'org' => 'ООО «Р.О.С.»', 'inn' => $inn, 'legal_address' => 'Москва', 'director' => 'Кузнецов']], $rules);
        $this->assertTrue($v->fails());
        $this->assertStringContainsString('не покупатель', $v->errors()->first('buyer.inn'));

        // Вписанный раньше «покупатель» Р.О.С. снимается переводом сделки через Р.О.С.
        $deal = $this->deal();
        $ros = User::create(['name' => 'ООО «Р.О.С.»', 'roles' => [Role::Buyer], 'manager_id' => $deal->buyer_id, 'approved_at' => now(), 'access' => []]);
        $party = Party::forUser($ros);
        $party->forceFill(['kind' => PartyKind::Company, 'inn' => $inn])->save();
        DealContract::for($deal)->forceFill(['buyer_user_id' => $ros->id, 'payer' => 'buyer'])->save();

        app(ChangeDealScheme::class)($deal->fresh(), DealScheme::Ros, $this->admin);
        $this->assertSame(DealScheme::Ros, $deal->fresh()->schemeOf());
        $this->assertNull(DealContract::for($deal->fresh())->buyer_user_id);
    }

    /**
     * Р.О.С. по умолчанию — счёт и договор на закупочную, подбор ссылкой (24.11.2026); сколько на счёт — вписывается (25.11.2026, владелец
     * по 7806/046/03674/26: «Р.О.С. 1 700 000, подбор ссылкой 160 000»), ссылка — остаток.
     */
    public function test_ros_selection_apart_by_default_and_car_price_by_hand(): void
    {
        $deal = $this->deal(true);
        app(ChangeDealScheme::class)($deal->fresh(), DealScheme::Ros, $this->admin);
        $deal = $deal->fresh();
        $deal->forceFill(['commission' => 50000])->save();
        DealContract::for($deal);
        $deal = $deal->fresh();
        $this->assertTrue($deal->splitsSelection());
        $this->assertTrue($deal->canSplitSelection());
        $this->assertSame((int) $deal->cost, DealContract::priceFor($deal));
        $this->assertSame(1150000 - 50000 - (int) $deal->cost, $deal->selectionDue());

        // Сверх закупочной в счёт — ссылка меньше на столько же; больше цены минус вознаграждение и меньше закупочной — нельзя.
        $car = (int) $deal->cost + 20000;
        app(\App\Offers\Actions\UpdateDealMoney::class)($deal, $this->admin, 50000, carPrice: $car, setCarPrice: true);
        $deal = $deal->fresh();
        $this->assertSame($car, $deal->contract->car_price);
        $this->assertSame($car, DealContract::priceFor($deal));
        $this->assertSame($car, $deal->contract->price);
        $this->assertSame(1150000 - 50000 - $car, $deal->selectionDue());
        foreach ([(int) $deal->cost - 1, 1150000 - 50000 + 1] as $bad) {
            try {
                app(\App\Offers\Actions\UpdateDealMoney::class)($deal, $this->admin, 50000, carPrice: $bad, setCarPrice: true);
                $this->fail('счёт '.$bad.' прошёл');
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertArrayHasKey('car_price', $e->errors());
            }
        }
        // Вознаграждение выросло больше ссылки — счёт ужимается, ссылка не уходит в минус.
        app(\App\Offers\Actions\UpdateDealMoney::class)($deal->fresh(), $this->admin, 1150000 - $car + 5000);
        $deal = $deal->fresh();
        $this->assertSame(0, $deal->linkDue());
        $this->assertSame(max((int) $deal->cost, $car - 5000), $deal->carPrice());
        // Снова закупочная — умолчание, хранится пустым.
        app(\App\Offers\Actions\UpdateDealMoney::class)($deal, $this->admin, 50000, carPrice: (int) $deal->cost, setCarPrice: true);
        $this->assertNull($deal->fresh()->contract->car_price);

        app(ChangeDealScheme::class)($deal, DealScheme::Prime, $this->admin);
        $prime = $deal->fresh();
        $this->assertFalse($prime->splitsSelection(), 'ПРАЙМ по умолчанию — в счёте за ТС');
        $this->assertTrue($prime->canSplitSelection());
    }

    /**
     * Письмо с реквизитами при Р.О.С. — реквизиты нашего юрлица (25.11.2026, Sitrak C7H 7806/046/03674/26): подтверждение переприняли
     * другим менеджером — новой сделке письмо прежней засчитывается; при «страховой напрямую» (реквизиты покупателя) — нет.
     */
    public function test_requisites_letter_survives_reaccept_when_we_pay_supplier(): void
    {
        $deal = $this->deal(true);
        app(ChangeDealScheme::class)($deal->fresh(), DealScheme::Ros, $this->admin);
        $deal->fresh()->forceFill(['created_at' => now()->subHour()])->save();
        $offer = $deal->offer->fresh();
        $stage = Stage::where('workflow_id', $offer->vendor->workflow(Track::Sale)->id)->where('name', 'Письмо с реквизитами')->firstOrFail();
        $this->assertTrue($stage->isRequisitesStep());
        $account = \Illuminate\Support\Facades\DB::table('mail_accounts')->insertGetId(['slug' => 'deal', 'title' => 'deal', 'email' => 'deal@xcar.ru', 'scope' => 'crm',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $thread = \App\Mail\Thread::create(['account_id' => $account, 'subject' => '5491/046/00861/26', 'offer_id' => $offer->id]);
        $letter = \App\Mail\Message::create(['account_id' => $account, 'thread_id' => $thread->id, 'direction' => 'out', 'from_email' => 'deal@xcar.ru',
            'subject' => 'Реквизиты для счёта', 'text_body' => 'Реквизиты Р.О.С.', 'date_at' => now()->subMinutes(10), 'sent_at' => now()->subMinutes(10)]);
        $offer->log(\App\Offers\OfferEventType::SupplierLetter, $this->admin, ['letter' => $letter->id, 'template_id' => $stage->template_id]);

        // Переприняли: прежняя сделка отменена, новая — через Р.О.С. же.
        $deal->fresh()->forceFill(['cancelled_at' => now(), 'closed_at' => now()])->save();
        $new = Deal::create(['offer_id' => $offer->id, 'buyer_id' => $deal->buyer_id, 'amount' => 1150000, 'cost' => $deal->cost, 'scheme' => DealScheme::Ros]);
        $offer->forceFill(['deal_id' => $new->id])->save();
        $this->assertTrue($new->created_at->gt($letter->sent_at));
        $this->assertSame($letter->id, \App\Offers\Listeners\MarkSupplierLetter::already($offer->fresh(), $stage)?->id);

        // «Страховой напрямую» — в письме реквизиты прежнего покупателя: новой сделке не годится.
        $new->update(['scheme' => DealScheme::Insurer]);
        $this->assertNull(\App\Offers\Listeners\MarkSupplierLetter::already($offer->fresh(), $stage));
    }
}
