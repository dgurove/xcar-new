<?php

namespace Tests\Feature;

use App\Billing\Party;
use App\Billing\Seller;
use App\Http\Admin\MailController;
use App\Mail\Message;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\ChangeDealScheme;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\SaveDealContract;
use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealScheme;
use App\Offers\InsurerReplies;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\AnswerRequirement;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Asks;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Альфа после «Подтвердили покупку» просит реквизиты для счёта (03.11.2026, владелец): «страховой напрямую» — данные
 * покупателя у менеджера (или админа за него), шаг проходит сам, когда они со сканом; платим мы (Р.О.С.) — сразу письмо с
 * нашими реквизитами. Ошибка молчалива: Альфа выставит счёт не на того или письмо уйдёт пустым.
 */
class AlfaRequisitesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private function deal(bool $vat): array
    {
        Storage::fake('private');
        Notification::fake();
        $this->admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Денис Трофимов', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        $vendor->forceFill(['pay_before_pickup' => true])->save();
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1200000, 'prices_include_vat' => $vat, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $this->manager, 1200000), $this->admin, 50000, CommissionMode::Payout,
            scheme: DealScheme::forOffer($offer->fresh()), ownerPrice: 900000);
        $this->exit($offer, 'Поставщик согласовал');
        $this->exit($offer, 'Беру в работу');
        $this->assertSame('Подтверждение покупки', $offer->fresh()->stage()->name);

        return [$offer->fresh(), $deal->fresh()];
    }

    private function exit(Offer $offer, string $label): void
    {
        $o = $offer->fresh();
        $deal = $o->deal()->first();
        $exit = $o->stage()->exits->first(fn ($e) => $e->label === $label && $e->fits($deal));
        $this->assertNotNull($exit, 'на «'.$o->stage()->name.'» нет «'.$label.'»');
        app(TakeExit::class)($o, $exit, $exit->actor, $exit->actor === Actor::Manager ? $this->manager : $this->admin);
    }

    /**
     * Подпункты шага (`Workflow\Substeps`, 04.11.2026): в «Согласовании» пройденное — исходом, кто нажал; в «Реквизитах для
     * счёта» — «Нет реквизитов» сейчас, «Письмо с реквизитами» впереди; во «Ждём менеджера» сделка — в группе своего менеджера.
     */
    public function test_substeps_and_waiting_tab_name_who_and_what(): void
    {
        [$offer] = $this->deal(vat: false);
        $position = $offer->position(Track::Sale);
        $subs = \App\Workflow\Substeps::for($position, \App\Workflow\Path::journal($offer, Track::Sale), $offer->deal);
        $this->assertSame([['Поставщик согласовал', 'done'], ['Беру в работу', 'done'], ['Подтверждение покупки', 'now']],
            $subs->map(fn ($s) => [$s['name'], $s['state']])->all());
        $this->assertSame([$this->admin->id, $this->manager->id], $subs->take(2)->pluck('user_id')->all());

        $this->exit($offer, 'Поставщик просит реквизиты');
        $offer = $offer->fresh();
        $subs = \App\Workflow\Substeps::for($offer->position(Track::Sale), \App\Workflow\Path::journal($offer, Track::Sale), $offer->deal);
        $this->assertSame('Реквизиты для счёта', $offer->stage()->block->name);
        $this->assertSame([['Нет реквизитов', 'now'], ['Письмо с реквизитами', 'wait']], $subs->map(fn ($s) => [$s['name'], $s['state']])->all());

        $page = $this->actingAs($this->admin)->get('http://'.\App\Support\Surface::Crm->host().'/work/deals?preset=manager')->assertOk();
        $page->assertSee('Денис Трофимов')->assertSee('Нет реквизитов')->assertSee('tel:79000000002', false);
    }

    public function test_reply_asking_requisites_is_recognized(): void
    {
        $ask = new Message(['direction' => 'in', 'text_body' => "Добрый день!\nПросьба прислать реквизиты для выставления счета. Без НДС.\nС уважением, Берсенева Елена"]);
        $other = new Message(['direction' => 'in', 'text_body' => "Добрый день!\nГОТС поступили на реализацию."]);
        $this->assertTrue(InsurerReplies::asksRequisites($ask));
        $this->assertFalse(InsurerReplies::asksRequisites($other));
    }

    public function test_insurer_scheme_asks_buyer_data_then_letter_with_passport(): void
    {
        [$offer, $deal] = $this->deal(vat: false);
        $this->assertSame(DealScheme::Insurer, $deal->scheme);

        $this->exit($offer, 'Поставщик просит реквизиты');
        $stage = $offer->fresh()->stage();
        $this->assertSame('Нет реквизитов', $stage->name);
        $this->assertSame(Asks::Buyer, $stage->asks);
        $this->assertTrue($deal->fresh()->collectsBuyer());
        $requirement = $deal->fresh()->openRequirement()->firstOrFail();
        $this->assertSame('Заполните данные покупателя', $requirement->title);

        // Кнопкой без данных шаг не пройти — письмо ушло бы пустым.
        try {
            app(AnswerRequirement::class)($requirement, $stage->exitsFor(Actor::Manager, $deal->fresh())->first(), $this->manager);
            $this->fail('без данных покупателя шаг прошёл');
        } catch (ValidationException) {
        }

        // Админ за менеджера: покупатель — сам менеджер, паспорт со сканом — шаг проходит сам, ход наш (письмо не ушло).
        app(SaveDealContract::class)(DealContract::for($deal->fresh()), ['buyer_id' => 'me'], $this->admin);
        $this->assertSame('Нет реквизитов', $offer->fresh()->stage()->name, 'без скана шаг стоит');
        $this->passportFor($this->manager);
        $offer = $offer->fresh();
        $this->assertSame('Письмо с реквизитами', $offer->stage()->name);
        $this->assertSame(WaitsFor::Us, $offer->position(Track::Sale)->waits_for);
        $this->assertNull($deal->fresh()->contract->ready_at, 'договора у «страховой напрямую» нет — «ДКП готов» не шлём');

        // В письме — реквизиты покупателя, скан — у него же (вложение черновика).
        $buyer = MailController::placeholders($offer)['buyer'];
        $this->assertStringContainsString('Денис Трофимов', $buyer);
        $this->assertStringContainsString('Паспорт: 11 11 № 111111', $buyer);
        $this->assertTrue($deal->fresh()->supplierInvoicee()->hasPassportScans());

        $this->exit($offer, 'Счёт от поставщика получен');
        $this->assertSame('Оплата счёта', $offer->fresh()->stage()->name);
    }

    public function test_ros_scheme_sends_our_requisites_without_manager(): void
    {
        [$offer, $deal] = $this->deal(vat: true);
        $this->assertSame(DealScheme::Ros, $deal->scheme);
        Party::seller(Seller::Ros)->update(['name' => 'ООО «Р.О.С.»', 'inn' => '7700000000', 'kind' => 'company']);

        $this->exit($offer, 'Поставщик просит реквизиты');
        $offer = $offer->fresh();
        $this->assertSame('Письмо с реквизитами', $offer->stage()->name);
        $this->assertNull($deal->fresh()->openRequirement()->first(), 'менеджеру задачи нет');
        $buyer = MailController::placeholders($offer)['buyer'];
        $this->assertStringContainsString('Р.О.С.', $buyer);
        $this->assertStringContainsString('ИНН 7700000000', $buyer);
    }

    public function test_scheme_shows_author_and_change_rehomes_requisites_step(): void
    {
        [$offer, $deal] = $this->deal(vat: false);
        $this->exit($offer, 'Поставщик просит реквизиты');
        $this->assertSame('Нет реквизитов', $offer->fresh()->stage()->name);
        $this->assertSame('Покупатель не выбран', $deal->fresh()->requisitesGap());

        // Админ меняет схему на ПРАЙМ — кто и когда записано, шаг сразу на письмо с нашими реквизитами.
        $this->actingAs($this->admin);
        app(ChangeDealScheme::class)($deal->fresh(), DealScheme::Prime, $this->admin);
        $deal = $deal->fresh();
        $this->assertSame(DealScheme::Prime, $deal->scheme);
        $this->assertSame($this->admin->id, $deal->scheme_by);
        $this->assertSame('Письмо с реквизитами', $offer->fresh()->stage()->name);
        $this->assertNull($deal->requisitesGap(), 'реквизиты ПРАЙМ есть — письмо можно');

        // Обратно на «страховой напрямую», письмо не ушло — назад к данным покупателя, менеджеру задача, письма нет.
        app(ChangeDealScheme::class)($deal, DealScheme::Insurer, $this->admin);
        $offer = $offer->fresh();
        $this->assertSame('Нет реквизитов', $offer->stage()->name);
        $this->assertNotNull($deal->fresh()->openRequirement()->first());
        $this->assertNull($offer->position(Track::Sale)->todo()['button']['letters'] ?? null);
    }

    public function test_letter_step_without_buyer_has_no_letter_button(): void
    {
        [$offer, $deal] = $this->deal(vat: false);
        $this->exit($offer, 'Поставщик просит реквизиты');
        // На шаг письма «страховой напрямую» без покупателя (руками «Вернуть на шаг» и т. п.) — «Ваш ход» просит заполнить.
        $letterStage = Stage::where('workflow_id', $offer->fresh()->stage()->workflow_id)->where('name', 'Письмо с реквизитами')->firstOrFail();
        app(EnterStage::class)($offer->fresh(), $letterStage, $this->admin);
        $todo = $offer->fresh()->position(Track::Sale)->todo();
        $this->assertSame('requisites', $todo['key']);
        $this->assertSame('Заполнить', $todo['button']['label']);
    }

    public function test_alfa_scheme_choices_default_first_and_prime_asks_twice(): void
    {
        Notification::fake();
        $this->admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Денис', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа выбор']);
        $vendor->forceFill(['pay_before_pickup' => true])->save();
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1200000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        $offer = $offer->fresh();

        // Без НДС: «Напрямую» первым и по умолчанию, ПРАЙМ последним, ДКП собственника у Альфы нет (03.11.2026).
        $this->assertSame([DealScheme::Insurer, DealScheme::Prime], DealScheme::casesFor($vendor, $offer));
        $this->assertNull(DealScheme::unusual($offer, DealScheme::Insurer));
        $this->assertStringContainsString('Напрямую', DealScheme::unusual($offer, DealScheme::Prime));

        // «Принять» через ПРАЙМ — сначала «Точно?», со вторым нажатием — принято.
        $bid = app(PlaceBid::class)($offer, $this->manager, 1200000);
        $url = 'http://'.config('xcar.crm_host').'/confirmations/'.$bid->id.'/accept';
        $this->actingAs($this->admin)->post($url, ['to' => 'deal', 'scheme' => 'prime', 'pickup' => 'us'])->assertSessionHasErrors(['scheme', 'scheme_check']);
        $this->assertNull($offer->fresh()->deal);
        $this->actingAs($this->admin)->post($url, ['to' => 'deal', 'scheme' => 'prime', 'pickup' => 'us', 'scheme_ok' => '1'])->assertSessionHasNoErrors();
        $deal = $offer->fresh()->deal;
        $this->assertSame(DealScheme::Prime, $deal->scheme);
        $this->assertSame($this->admin->id, $deal->scheme_by);
    }

    public function test_supplier_may_invoice_right_away(): void
    {
        [$offer] = $this->deal(vat: false);
        $this->exit($offer, 'Поставщик сразу выставил счёт');
        $this->assertSame('Оплата счёта', $offer->fresh()->stage()->name);
    }
}
