<?php

namespace Tests\Feature;

use App\Chats\Message as ChatMessage;
use App\Mail\Extraction\Code;
use App\Mail\Extraction\Templates\Tinkoff;
use App\Mail\Message;
use App\Notifications\Notify;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\FillFromLetter;
use App\Offers\Actions\PlaceBid;
use App\Offers\CommissionMode;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Т-Страхование (маршрут `TBank`): сначала «Берёте в работу?» с машиной и VIN, и только после «Беру в работу» — письмо
 * поставщику, одинаковым текстом «Прошу прислать контакты…» (15.11.2026: письмо «интерес за 500 000 ₽» ушло до согласия и
 * при лоте 833 000). Ошибка здесь — письмо поставщику, которое не отзовёшь.
 */
class TBankConsentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    /** Письмо Т-Страхования «Предлагаем принять участие в оценке ТС» по этой машине — с VIN в теме. */
    private function tbankLetter(Offer $offer, string $vin = 'WDD2050402R514538'): Message
    {
        $account = DB::table('mail_accounts')->where('slug', 'deal')->value('id') ?? DB::table('mail_accounts')->insertGetId(['slug' => 'deal', 'title' => 'deal', 'email' => 'deal@xcar.ru', 'scope' => 'offers',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $thread = \App\Mail\Thread::create(['account_id' => $account, 'subject' => $offer->claim_ref, 'offer_id' => $offer->id, 'last_message_at' => now()]);

        return Message::create(['account_id' => $account, 'thread_id' => $thread->id, 'direction' => 'in', 'from_email' => 'p.roevnev@tbank.ru', 'date_at' => now(),
            'subject' => $offer->claim_ref.' || 2019 Mercedes-Benz C-class (Р380КХ774 / '.$vin.') || Москва',
            'parsed' => ['keys' => ['code:'.Code::key($offer->claim_ref)], 'fields' => ['vin' => ['value' => $vin, 'source' => 'subject'], 'floor_price' => ['value' => 833000, 'source' => 'body']],
                'own_text' => "Уважаемые партнеры, добрый день!\nПредлагаем принять участие в оценке ТС. Ответ просьба дать к 17.11 до 15:00\n*Цена за Лот* 833 000 *р.\nТС не кредитное, ограничений ГИБДД нет."]]);
    }

    private function accepted(bool $letter = true, ?string $vin = 'WDD2050402R514538'): array
    {
        Storage::fake('private');
        Notification::fake();
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Менеджер', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Т-Страхование тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::TBank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => 'AUT-26-781222', 'vin' => $vin, 'floor_price' => 500000,
            'owner_price' => 500000, 'asking_price' => 650000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $letter && $this->tbankLetter($offer);
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $this->manager, 600000), $this->admin, 0, CommissionMode::Withheld, scheme: DealScheme::OwnerDkp);

        return [$offer->fresh(), $deal->fresh()];
    }

    public function test_staff_asks_manager_with_vin_then_letter_with_lot_price(): void
    {
        [$offer, $deal] = $this->accepted();
        // Письмо Т-Страхования уже есть — ход наш, но менеджера спрашивает сотрудник кнопкой, как у Альфы.
        $this->assertSame('Ждём письмо поставщика', $offer->stage()->name);
        $this->assertSame(\App\Workflow\WaitsFor::Us, $offer->position(Track::Sale)->waits_for);
        $this->assertFalse(ChatMessage::whereNotNull('requirement_id')->exists(), 'само менеджера не спрашиваем');
        app(TakeExit::class)($offer, $offer->stage()->exitsFor(Actor::Staff, $deal)->firstWhere('label', \App\Offers\SupplierLetterWait::EXIT), Actor::Staff, $this->admin);
        $offer = $offer->fresh();
        $stage = $offer->stage();
        $this->assertSame('Согласие менеджера', $stage->name);
        // Письма поставщику на этом шаге нет — ни кнопки, ни окна после «Принять».
        $this->assertNull($offer->vendorLetterUrl($stage));
        $ask = ChatMessage::whereNotNull('requirement_id')->latest('id')->firstOrFail();
        $this->assertStringStartsWith(Notify::CONSENT_ASK."\n", $ask->text);
        $this->assertStringContainsString('VIN WDD2050402R514538', $ask->text);

        app(TakeExit::class)($offer, $stage->exitsFor(Actor::Manager, $deal)->firstWhere('label', 'Беру в работу'), Actor::Manager, $this->manager);
        $offer = $offer->fresh();
        $position = $offer->position(Track::Sale);
        $this->assertSame('Письмо о покупке', $position->stage->name);
        $this->assertSame('unsent', $position->letterStatus());
        // Как пишет начальник: машина, VIN, номер и закупочная (после переоценки, если «Цена за Лот» была другой).
        $this->assertSame("Здравствуйте!\n\nВыражаем готовность к покупке {{ car }}, VIN {{ vin }} ({{ claim_ref }}) за {{ floor }}\nПросим прислать контакты и данные для организации сделки.\n\nС уважением,\nАндрей Кузнецов", $position->stage->template->body);
    }

    public function test_without_supplier_letter_manager_is_not_asked_until_it_arrives_with_vin(): void
    {
        [$offer, $deal] = $this->accepted(letter: false, vin: null);
        $this->assertSame('Ждём письмо поставщика', $offer->stage()->name);
        $this->assertFalse(ChatMessage::whereNotNull('requirement_id')->exists(), 'без письма менеджера не спрашиваем');

        $this->assertNotSame(\App\Workflow\WaitsFor::Us, $offer->position(Track::Sale)->waits_for);

        // Пришло письмо (как в `OnMessage`): VIN — в машину, ход — наш; сам шаг не уходит.
        $message = $this->tbankLetter($offer);
        app(FillFromLetter::class)($offer->fresh(), $message, read: false);
        $this->assertTrue(\App\Offers\SupplierLetterWait::mark($offer->fresh()));
        $this->assertSame('Ждём письмо поставщика', $offer->fresh()->stage()->name);
        $this->assertSame(\App\Workflow\WaitsFor::Us, $offer->fresh()->position(Track::Sale)->waits_for);
        $this->assertFalse(ChatMessage::whereNotNull('requirement_id')->exists());

        // «Спросить менеджера» — вопрос с VIN из письма.
        $o = $offer->fresh();
        app(TakeExit::class)($o, $o->stage()->exitsFor(Actor::Staff, $deal)->firstWhere('label', \App\Offers\SupplierLetterWait::EXIT), Actor::Staff, $this->admin);
        $this->assertSame('Согласие менеджера', $offer->fresh()->stage()->name);
        $this->assertStringContainsString('VIN WDD2050402R514538', ChatMessage::whereNotNull('requirement_id')->latest('id')->firstOrFail()->text);
    }

    public function test_lot_price_differs_is_reprice_and_manager_agreeing_on_price_takes_the_deal(): void
    {
        [$offer, $deal] = $this->accepted();
        $message = Message::latest('id')->firstOrFail();
        // «Цена за Лот» 833 000 при закупочной 500 000 — переоценка на решение, сама цена не меняется.
        $this->assertSame(833000, \App\Mail\Extraction\Templates\Template::lotPrice($message->ownText()));
        $reprice = app(\App\Offers\Actions\DetectReprice::class)($offer->fresh(), $message);
        $this->assertSame([500000, 833000], [$reprice->from_price, $reprice->to_price]);
        $this->assertSame(500000, $offer->fresh()->floor_price);
        // В сделке — шаг «Переоценка» без «Ответа поставщику»: ответом будет наше «Письмо о покупке».
        $flow = \App\Offers\RepriceFlow::for($offer->fresh());
        $this->assertNotNull($flow);
        $this->assertNull($flow->answer());

        // «Спросить» менеджера по сделке → он назвал цену → приняли: это и есть «Беру в работу».
        app(\App\Offers\Actions\ApplyReprice::class)($reprice->fresh(), $this->admin, 833000, [], \App\Offers\Actions\ApplyReprice::ASK);
        $ask = \App\Offers\RepriceAsk::where('deal_id', $deal->id)->latest('id')->firstOrFail();
        app(\App\Offers\Actions\AnswerReprice::class)->offer($ask, 900000, $this->manager, lowOk: true);
        app(\App\Offers\Actions\AnswerReprice::class)->accept($ask->fresh(), $this->admin);
        $this->assertSame('Письмо о покупке', $offer->fresh()->stage()->name);
        $this->assertSame(833000, $deal->fresh()->cost);
        $this->assertFalse(ChatMessage::where('text', 'like', Notify::CONSENT_ASK.'%')->exists(), 'второго вопроса «берём в работу?» нет');
        $this->assertNull(\App\Offers\RepriceFlow::for($offer->fresh()));
    }

    public function test_changed_mind_leads_back_to_bidding(): void
    {
        [$offer, $deal] = $this->accepted();
        app(TakeExit::class)($offer, $offer->stage()->exitsFor(Actor::Staff, $deal)->firstWhere('label', \App\Offers\SupplierLetterWait::EXIT), Actor::Staff, $this->admin);
        $offer = $offer->fresh();
        $exits = $offer->stage()->exitsFor(Actor::Manager, $deal);
        $this->assertSame(['Беру в работу', 'Я передумал'], $exits->pluck('label')->all());
        $this->assertSame('Приём подтверждений', $exits->firstWhere('label', 'Я передумал')->to->name);
        $this->assertTrue($exits->firstWhere('label', 'Я передумал')->isRetreat());
    }

    public function test_vin_from_tbank_subjects_and_reply_fills_empty_vin_of_sold_offer(): void
    {
        foreach ([
            'AUT-26-781222 || 2019 Mercedes-Benz C-class (Р380КХ774 / WDD2050402R514538) || Москва' => 'WDD2050402R514538',
            'RE: AUT-26-1003653 2014 Mitsubishi Outlander (О345ЕА47 / Z8TXTGF2WEM014888)' => 'Z8TXTGF2WEM014888',
            'AUT-26-971451 // 2025 Volkswagen Tiguan L (А333ВР64 / LSVXEABD4S2033884)' => 'LSVXEABD4S2033884',
        ] as $subject => $vin) {
            $this->assertSame($vin, (new Tinkoff)->extract($subject, '')['vin']['value'] ?? null, $subject);
        }

        $vendor = Vendor::create(['name' => 'Т-Страхование тест', 'senders' => ['tbank.ru']]);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => 'AUT-26-1003653']);
        $offer->forceFill(['state' => OfferState::Sold])->save();
        $account = DB::table('mail_accounts')->insertGetId(['slug' => 'deal', 'title' => 'deal', 'email' => 'deal@xcar.ru', 'scope' => 'offers',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $message = Message::create(['account_id' => $account, 'direction' => 'in', 'from_email' => 'p.roevnev@tbank.ru',
            'subject' => 'RE: AUT-26-1003653 2014 Mitsubishi Outlander (О345ЕА47 / Z8TXTGF2WEM014888)',
            'parsed' => ['keys' => ['code:'.Code::key('AUT-26-1003653')], 'fields' => ['vin' => ['value' => 'Z8TXTGF2WEM014888', 'source' => 'subject']]]]);

        app(FillFromLetter::class)($offer->fresh(), $message, read: false);
        $this->assertSame('Z8TXTGF2WEM014888', $offer->fresh()->vin);
    }
}
