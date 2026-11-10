<?php

namespace Tests\Feature;

use App\Billing\Actions\RecordPayment;
use App\Billing\InvoiceState;
use App\Billing\PaymentSource;
use App\Mail\Extraction\Code;
use App\Mail\Extraction\Templates\Template;
use App\Mail\Message;
use App\Notifications\DealOpenedNotice;
use App\Notifications\RepriceAnsweredNotice;
use App\Notifications\RepriceAskNotice;
use App\Notifications\RepriceDecidedNotice;
use App\Notifications\RepriceNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\AnswerReprice;
use App\Offers\Actions\ApplyReprice;
use App\Offers\Actions\DetectReprice;
use App\Offers\Actions\DismissReprice;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\UpdateOffer;
use App\Offers\BidState;
use App\Offers\CommissionMode;
use App\Offers\DealScheme;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Pricing;
use App\Offers\Reprice;
use App\Offers\RepriceAsk;
use App\Offers\RepriceFlow;
use App\Telegram\UpdateHandler;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Переоценка закупочной (28.10.2026, владелец: «по письму — принять или отменить», «у тех, кто подтвердил, и в сделке —
 * оставить прежнюю или обновить»). Ошибка молчит и стоит денег: не та закупочная — не тот счёт и ДКП.
 */
class RepriceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $roman;

    private User $denis;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Notification::fake();
        config(['xcar.yookassa.shop_id' => '1', 'xcar.yookassa.secret' => 'test']);
        $this->admin = User::create(['name' => 'Катя', 'phone' => '79000000030', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->roman = User::create(['name' => 'Роман', 'phone' => '79000000017', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->denis = User::create(['name' => 'Денис', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 594762, 'asking_price' => 1050000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        foreach ([$this->roman, $this->denis] as $m) {
            $offer->viewers()->create(['user_id' => $m->id, 'opens_at' => now()]);
        }
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $this->offer = $offer->fresh();
    }

    public function test_letter_phrases(): void
    {
        $this->assertSame(433200, Template::newPrice("Всем привет!\nНовая стоимость – 433 200 руб.\nКорочкин Михаил"));
        $this->assertSame(234000, Template::newPrice('Новая стоимость - 234 000 руб.'));
        $this->assertNull(Template::newPrice('будем переоценивать с учетом разукомплекта. Новая цена будет к концу следующей недели.'));
        $this->assertNull(Template::newPrice('Стоимость изменилась на 50 000 руб.'));
    }

    public function test_letter_about_this_car_opens_reprice_and_changes_nothing(): void
    {
        $this->offer->update(['claim_ref' => '653253/2026']);
        $account = DB::table('mail_accounts')->insertGetId(['slug' => 'offer', 'title' => 'offer', 'email' => 'offer@xcar.ru', 'scope' => 'offers',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $letter = fn (string $code) => Message::create(['account_id' => $account, 'direction' => 'in', 'from_email' => 'korochkin@sovcomins.ru',
            'subject' => 'RE: '.$code.' KIA CERATO', 'parsed' => ['keys' => ['code:'.Code::key($code)], 'own_text' => 'Всем привет! Новая стоимость – 433 200 руб.']]);

        $this->assertNull(app(DetectReprice::class)($this->offer->fresh(), $letter('111111/2026')));
        $reprice = app(DetectReprice::class)($this->offer->fresh(), $message = $letter('653253/2026'));
        $this->assertSame([594762, 433200, 'letter', $message->id], [$reprice->from_price, $reprice->to_price, $reprice->source, $reprice->message_id]);
        $this->assertSame(594762, $this->offer->fresh()->floor_price);
        Notification::assertSentTo($this->admin, RepriceNotice::class);
        // То же письмо второй раз — вторая плашка не заводится.
        $this->assertNull(app(DetectReprice::class)($this->offer->fresh(), $message));
    }

    public function test_value_vendor_letter_amount_is_value_and_floor_follows_rule(): void
    {
        $this->offer->vendor->update(['floor_by_value' => true]);
        $this->offer->update(['claim_ref' => '653253/2026', 'value' => 738655, 'floor_price' => 813000]);
        $account = DB::table('mail_accounts')->insertGetId(['slug' => 'offer', 'title' => 'offer', 'email' => 'offer@xcar.ru', 'scope' => 'offers',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $message = Message::create(['account_id' => $account, 'direction' => 'in', 'from_email' => 'expert@alfastrah.ru', 'subject' => 'RE: 653253/2026',
            'parsed' => ['keys' => ['code:'.Code::key('653253/2026')], 'own_text' => 'Новая стоимость – 700 000 руб.']]);

        $reprice = app(DetectReprice::class)($this->offer->fresh(), $message);
        $this->assertSame([Pricing::rebid(700000), 700000], [$reprice->to_price, $reprice->value]);
        app(ApplyReprice::class)($reprice, $this->admin, $reprice->to_price, [], null);
        $this->assertSame([700000, Pricing::rebid(700000)], [$this->offer->fresh()->value, $this->offer->fresh()->floor_price]);
    }

    public function test_old_bid_without_own_cost_keeps_previous_and_manager_sees_declared_not_floor(): void
    {
        $old = app(PlaceBid::class)($this->offer, $this->roman, 1000000);
        $old->update(['cost' => null]);
        $denis = app(PlaceBid::class)($this->offer->fresh(), $this->denis, 980000);
        app(UpdateOffer::class)($this->offer->fresh(), ['floor_price' => 500000], $this->admin);
        // Вписали ещё раз до решения — прежняя переоценка заменена, в истории это видно.
        app(UpdateOffer::class)($this->offer->fresh(), ['floor_price' => 433200], $this->admin);
        $this->assertSame(1, Reprice::where('state', Reprice::SUPERSEDED)->count());
        $this->assertTrue($this->offer->events()->where('type', 'reprice')->get()->contains(fn ($e) => str_contains($e->text(), 'заменена новой')));

        app(ApplyReprice::class)($this->offer->fresh()->pendingReprice, $this->admin, 433200, [$old->id => 'keep', $denis->id => 'update'], null);
        $this->assertSame(594762, $old->fresh()->cost);
        // Закупочная снизилась — Денису молча (28.10.2026, владелец: «когда понижается, уведомлять не надо»).
        $this->assertSame(433200, $denis->fresh()->cost);
        Notification::assertNotSentTo($this->denis, RepriceAskNotice::class);
    }

    public function test_edit_not_by_crm_editor_notifies_admins(): void
    {
        app(PlaceBid::class)($this->offer, $this->roman, 1000000);
        // Правка не админом в редакторе (модератор, парковка, «из текста») — уведомление админам: иначе молча не применится.
        $moderator = User::create(['name' => 'Лиза', 'phone' => '79000000050', 'roles' => [Role::Moderator], 'approved_at' => now()]);
        app(UpdateOffer::class)($this->offer->fresh(), ['floor_price' => 400000], $moderator);
        $this->assertSame(400000, $this->offer->fresh()->pendingReprice->to_price);
        Notification::assertSentTo($this->admin, RepriceNotice::class);
    }

    public function test_edit_with_confirmers_becomes_reprice_without_confirmers_writes(): void
    {
        // Подтвердивших нет — правка пишется сразу.
        app(UpdateOffer::class)($this->offer, ['floor_price' => 590000], $this->admin);
        $this->assertSame(590000, $this->offer->fresh()->floor_price);

        app(PlaceBid::class)($this->offer->fresh(), $this->roman, 1000000);
        app(UpdateOffer::class)($this->offer->fresh(), ['floor_price' => 433200, 'description' => 'Новая'], $this->admin);
        $offer = $this->offer->fresh();
        $this->assertSame(590000, $offer->floor_price);
        $this->assertSame('Новая', $offer->description);
        $reprice = $offer->pendingReprice;
        $this->assertSame([590000, 433200, 'manual'], [$reprice->from_price, $reprice->to_price, $reprice->source]);

        // Отклонили — деньги не тронуты, плашки нет.
        app(DismissReprice::class)($reprice, $this->admin);
        $this->assertNull($offer->fresh()->pendingReprice);
        $this->assertSame(590000, $offer->fresh()->floor_price);
    }

    public function test_each_confirmer_keeps_or_updates(): void
    {
        $roman = app(PlaceBid::class)($this->offer, $this->roman, 1000000);
        $denis = app(PlaceBid::class)($this->offer->fresh(), $this->denis, 980000);
        app(UpdateOffer::class)($this->offer->fresh(), ['floor_price' => 433200], $this->admin);
        $reprice = $this->offer->fresh()->pendingReprice;
        // Плашка и шторка в редакторе и окошке: оба подтвердивших с выбором, метка у строки списка.
        $page = $this->actingAs($this->admin)->get('http://crm.xcar.localhost/offers/'.$this->offer->number)->assertOk()->getContent();
        $this->assertStringContainsString('Применить переоценку', $page);
        $this->assertSame(2, substr_count($page, 'value="update"'));
        $list = $this->actingAs($this->admin)->get('http://crm.xcar.localhost/offers?preset=bids&vid=table&peek='.$this->offer->number)->assertOk()->getContent();
        $this->assertStringContainsString('переоценка', $list);
        $this->assertStringContainsString('Применить переоценку', $list);

        // Без решения по каждому — отказ, ничего не меняется.
        try {
            app(ApplyReprice::class)($reprice, $this->admin, 433200, [$roman->id => 'update'], null);
            $this->fail('без решения по Денису прошло');
        } catch (ValidationException) {
        }
        $this->assertSame(594762, $this->offer->fresh()->floor_price);

        app(ApplyReprice::class)($reprice, $this->admin, 433200, [$roman->id => 'update', $denis->id => 'keep'], null);
        $this->assertSame(433200, $this->offer->fresh()->floor_price);
        $this->assertSame(433200, $roman->fresh()->cost);
        $this->assertSame(594762, $denis->fresh()->cost);
        $this->assertSame(Reprice::APPLIED, $reprice->fresh()->state);
        Notification::assertNotSentTo($this->roman, RepriceAskNotice::class);
    }

    public function test_deal_update_reissues_unpaid_and_keep_goes_to_supplier_cost(): void
    {
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($this->offer, $this->roman, 1050000), $this->admin, 20000, CommissionMode::Payout, scheme: DealScheme::Insurer);
        $this->pickedUp();
        $first = $deal->fresh()->issuedInvoices()->sole();
        $this->assertEqualsWithDelta(1050000 - 594762 - 20000, $first->total, 0.01);

        // «Оставить» — менеджеру как было, новая — только закупочная поставщика.
        app(UpdateOffer::class)($this->offer->fresh(), ['floor_price' => 500000], $this->admin);
        app(ApplyReprice::class)($this->offer->fresh()->pendingReprice, $this->admin, 500000, [], 'keep');
        $this->assertSame([594762, 500000], [$deal->fresh()->cost, $deal->fresh()->supplier_cost]);
        Notification::assertNotSentTo($this->roman, RepriceAskNotice::class);

        // «Обновить» — закупочная и сумма страховой новые, неоплаченный подбор перевыставлен.
        app(UpdateOffer::class)($this->offer->fresh(), ['floor_price' => 433200], $this->admin);
        app(ApplyReprice::class)($this->offer->fresh()->pendingReprice, $this->admin, 433200, [], 'update');
        $deal = $deal->fresh();
        $this->assertSame([433200, null, 433200], [$deal->cost, $deal->supplier_cost, (int) $deal->owner_price]);
        $this->assertSame(InvoiceState::Void, $first->fresh()->state);
        $this->assertEqualsWithDelta(1050000 - 433200 - 20000, $deal->issuedInvoices()->where('state', InvoiceState::Issued)->sole()->total, 0.01);
        Notification::assertNotSentTo($this->roman, RepriceAskNotice::class);

        // Заплатили — «Обновить» сделке больше нельзя.
        app(RecordPayment::class)($deal->issuedInvoices()->where('state', InvoiceState::Issued)->sole(), $this->admin, 1000, now(), PaymentSource::Bank);
        app(UpdateOffer::class)($this->offer->fresh(), ['floor_price' => 400000], $this->admin);
        $this->expectException(ValidationException::class);
        app(ApplyReprice::class)($this->offer->fresh()->pendingReprice, $this->admin, 400000, [], 'update');
    }

    private function pickedUp(): void
    {
        $stage = $this->offer->fresh()->position(Track::Sale)->stage;
        app(TakeExit::class)($this->offer->fresh(), $stage->exits->firstWhere('label', 'Автомобиль забрал'), Actor::Manager, $this->roman);
    }

    /**
     * Выросла закупочная, «Спросить» (10.11.2026, владелец по 0790/046/09068/26: «Согласен» менял только закупочную, сделка
     * стояла в минус): менеджер видит было → стало и вводит новую цену подтверждения, админ принимает, отвечает
     * контрпредложением или отказывает.
     */
    public function test_increase_asks_manager_price_and_admin_decides(): void
    {
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($this->offer, $this->roman, 1050000), $this->admin, 20000, CommissionMode::Payout, scheme: DealScheme::Insurer);
        $this->pickedUp();
        $first = $deal->fresh()->issuedInvoices()->sole();

        // Закупочная выросла, админ выбрал «Спросить» — цена у менеджера прежняя, ему вопрос, у нас — ждём.
        app(UpdateOffer::class)($this->offer->fresh(), ['floor_price' => 650000], $this->admin);
        app(ApplyReprice::class)($this->offer->fresh()->pendingReprice, $this->admin, 650000, [], 'ask', ['asking_price' => 1150000]);
        $this->assertSame(594762, $deal->fresh()->cost);
        $ask = RepriceAsk::sole();
        $this->assertTrue($ask->alive());
        $this->assertSame(1150000, $ask->asking_to);
        Notification::assertSentTo($this->roman, RepriceAskNotice::class, fn (RepriceAskNotice $n) => $n->critical()
            && collect($n->toTelegram()['keyboard'][0])->pluck('text')->all() === ['Отказываюсь'] && $n->toTelegram()['button'] === 'Ввести цену');
        $this->assertSame('Назовите новую цену', $deal->fresh()->managerTask()['title']);
        $this->actingAs($this->roman)->get('http://xcar.localhost/deals/'.$deal->id)->assertOk()
            ->assertSee('Новая цена подтверждения')->assertSee(\App\Support\Money::nums(594762), false)->assertSee(\App\Support\Money::nums(1150000), false);

        // Срок вышел — горит у нас.
        $this->travel(2)->days();
        $position = $this->offer->fresh()->position();
        $this->assertTrue($position->needsUs());
        $this->assertSame('price-ask', $position->todo()['key']);
        $this->travelBack();

        // Недопустимая цена — ошибкой, ничего не меняется.
        $this->actingAs($this->roman)->from('http://xcar.localhost/deals/'.$deal->id)->post('http://xcar.localhost/reprice-asks/'.$ask->id, ['do' => 'price', 'price' => '600 000'])
            ->assertSessionHasErrors('amount');
        $this->assertSame(RepriceAsk::PENDING, $ask->fresh()->state);

        // Назвал цену — решаем мы: сделка прежняя, «Ваш ход» у нас, админам — важное.
        $this->actingAs($this->roman)->post('http://xcar.localhost/reprice-asks/'.$ask->id, ['do' => 'price', 'price' => '1 100 000'])->assertRedirect();
        $ask->refresh();
        $this->assertSame([RepriceAsk::OFFERED, 1100000], [$ask->state, $ask->offered_price]);
        $this->assertSame([594762, 1050000], [$deal->fresh()->cost, (int) $deal->fresh()->amount]);
        $position = $this->offer->fresh()->position();
        $this->assertSame('price-offer', $position->todo()['key']);
        $this->assertTrue(\App\Workflow\Position::whereKey($position->id)->needsUs()->exists());
        $this->assertNotSame('Назовите новую цену', $deal->fresh()->managerTask()['title'] ?? null);
        Notification::assertSentTo($this->admin, RepriceAnsweredNotice::class, fn (RepriceAnsweredNotice $n) => $n->important() && str_contains($n->title(), 'предлагает'));

        // Контрпредложение — ход снова его; не согласен — снова его цена.
        $this->actingAs($this->admin)->post('http://crm.xcar.localhost/offers/'.$this->offer->number.'/reprice-asks/'.$ask->id, ['do' => 'counter', 'counter_price' => '1 150 000'])->assertRedirect();
        $this->assertSame([RepriceAsk::COUNTERED, 1150000], [$ask->fresh()->state, $ask->fresh()->counter_price]);
        Notification::assertSentTo($this->roman, RepriceDecidedNotice::class, fn (RepriceDecidedNotice $n) => str_starts_with($n->title(), 'Контрпредложение'));
        $this->assertSame('Назовите новую цену', $deal->fresh()->managerTask()['title']);
        $this->actingAs($this->roman)->get('http://xcar.localhost/deals/'.$deal->id)->assertSee('Контрпредложение')->assertSee(\App\Support\Money::nums(1150000), false);
        $this->actingAs($this->roman)->post('http://xcar.localhost/reprice-asks/'.$ask->id, ['do' => 'refuse'])->assertRedirect();
        $this->assertSame(RepriceAsk::OFFERED, $ask->fresh()->state);

        // Приняли — новая закупочная и его цена, подбор перевыставлен; ему — «Цена принята».
        $this->actingAs($this->admin)->post('http://crm.xcar.localhost/offers/'.$this->offer->number.'/reprice-asks/'.$ask->id, ['do' => 'accept'])->assertRedirect();
        $deal = $deal->fresh();
        $this->assertSame([650000, 1100000, null], [$deal->cost, (int) $deal->amount, $deal->supplier_cost]);
        $this->assertSame(1100000, (int) $deal->bid->amount, 'подтверждение сделки — та же цена');
        $this->actingAs($this->admin)->get('http://crm.xcar.localhost/offers/'.$this->offer->number)->assertOk()->assertSee(\App\Support\Money::rub(1100000), false);
        $this->assertSame(InvoiceState::Void, $first->fresh()->state);
        $this->assertEqualsWithDelta(1100000 - 650000 - 20000, $deal->issuedInvoices()->where('state', InvoiceState::Issued)->sole()->total, 0.01);
        $this->assertSame(RepriceAsk::AGREED, $ask->fresh()->state);
        $this->assertNull($deal->marginMark());
        Notification::assertSentTo($this->roman, RepriceDecidedNotice::class, fn (RepriceDecidedNotice $n) => str_starts_with($n->title(), 'Цена принята'));
    }

    /** Админ отказал по названной цене — сделка отменена, менеджеру сообщение; цена за менеджера — сразу в сделку. */
    public function test_admin_rejects_offered_price_or_enters_it_for_manager(): void
    {
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($this->offer, $this->roman, 1050000), $this->admin, 20000, CommissionMode::Payout, scheme: DealScheme::Insurer);
        app(UpdateOffer::class)($this->offer->fresh(), ['floor_price' => 650000], $this->admin);
        app(ApplyReprice::class)($this->offer->fresh()->pendingReprice, $this->admin, 650000, [], 'ask');
        $ask = RepriceAsk::sole();

        // За менеджера ниже новой закупочной — нельзя.
        $crm = 'http://crm.xcar.localhost/offers/'.$this->offer->number.'/reprice-asks/'.$ask->id;
        $this->actingAs($this->admin)->post($crm, ['do' => 'price', 'reprice_price' => '640 000'])->assertSessionHasErrors('reprice_price');
        // Сказал устно 1 120 000 — сразу в сделке.
        $this->actingAs($this->admin)->post($crm, ['do' => 'price', 'reprice_price' => '1 120 000'])->assertRedirect();
        $this->assertSame([650000, 1120000], [$deal->fresh()->cost, (int) $deal->fresh()->amount]);
        $this->assertSame(RepriceAsk::AGREED, $ask->fresh()->state);

        // Новая переоценка, он назвал цену — отказали: сделка отменена, ему сообщение.
        app(UpdateOffer::class)($this->offer->fresh(), ['floor_price' => 700000], $this->admin);
        app(ApplyReprice::class)($this->offer->fresh()->pendingReprice, $this->admin, 700000, [], 'ask');
        $ask = RepriceAsk::where('state', RepriceAsk::PENDING)->sole();
        app(AnswerReprice::class)->offer($ask, 1130000, $this->roman);
        $this->actingAs($this->admin)->post('http://crm.xcar.localhost/offers/'.$this->offer->number.'/reprice-asks/'.$ask->id, ['do' => 'decline'])->assertRedirect();
        $this->assertSame(DealState::Cancelled, $deal->fresh()->state);
        Notification::assertSentTo($this->roman, RepriceDecidedNotice::class, fn (RepriceDecidedNotice $n) => str_starts_with($n->title(), 'Сделка отменена'));
    }

    /** По подтверждению названная цена сразу его: сумма и новая закупочная. */
    public function test_bid_question_takes_new_price(): void
    {
        $bid = app(PlaceBid::class)($this->offer, $this->roman, 1050000);
        app(UpdateOffer::class)($this->offer->fresh(), ['floor_price' => 650000], $this->admin);
        app(ApplyReprice::class)($this->offer->fresh()->pendingReprice, $this->admin, 650000, [$bid->id => 'ask'], null);
        $ask = RepriceAsk::sole();
        app(AnswerReprice::class)->offer($ask, 1100000, $this->roman);
        $this->assertSame([1100000, 650000], [(int) $bid->fresh()->amount, (int) $bid->fresh()->cost]);
        $this->assertSame(RepriceAsk::AGREED, $ask->fresh()->state);
    }

    public function test_increase_declined_cancels_deal_and_withdraws_bid(): void
    {
        $denis = app(PlaceBid::class)($this->offer, $this->denis, 980000);
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($this->offer->fresh(), $this->roman, 1050000), $this->admin, 20000, CommissionMode::Payout, scheme: DealScheme::Insurer);
        app(UpdateOffer::class)($this->offer->fresh(), ['floor_price' => 650000], $this->admin);
        app(ApplyReprice::class)($this->offer->fresh()->pendingReprice, $this->admin, 650000, [$denis->id => 'ask'], 'ask');
        $this->assertSame(2, RepriceAsk::pending()->count());

        // Денис отказался из Telegram — подтверждение снято.
        $this->denis->forceFill(['telegram_chat_id' => 555])->save();
        $denisAsk = RepriceAsk::where('bid_id', $denis->id)->sole();
        config(['xcar.telegram.token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $press = fn (string $a) => app(UpdateHandler::class)->handle(['update_id' => random_int(1, 9999), 'callback_query' => [
            'id' => 'q', 'from' => ['id' => 555], 'data' => "reprice:{$denisAsk->id}:{$a}", 'message' => ['message_id' => 1, 'chat' => ['id' => 555]]]]);
        $press('no');
        $this->assertTrue($denisAsk->fresh()->isPending());
        $press('sureno');
        $this->assertSame(BidState::Withdrawn, $denis->fresh()->state);

        // За Романа отказался админ — сделка отменена, ТС в продаже, в истории «за менеджера».
        $ask = RepriceAsk::where('deal_id', $deal->id)->sole();
        $this->actingAs($this->admin)->post('http://crm.xcar.localhost/offers/'.$this->offer->number.'/reprice-asks/'.$ask->id, ['agree' => 0])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(DealState::Cancelled, $deal->fresh()->state);
        // Без фото к продаже не готово — в черновики (с фото — в продажу).
        $this->assertContains($this->offer->fresh()->state, [OfferState::Open, OfferState::Draft]);
        $this->assertTrue($this->offer->events()->where('type', 'reprice')->get()->contains(fn ($e) => str_contains($e->text(), 'за менеджера')));
        // Админу — только ответ Дениса: свой ответ за Романа ему не звенит.
        Notification::assertSentToTimes($this->admin, RepriceAnsweredNotice::class, 1);
    }

    /**
     * Пока менеджер сделки думает о новой закупочной — машину видят все менеджеры обычным предложением (04.11.2026, владелец:
     * «опубликовать снова для всех на неделю, согласится — сразу убрать»); подтверждение — резервом, согласие снимает показ.
     */
    public function test_show_to_all_while_manager_thinks(): void
    {
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($this->offer, $this->roman, 1050000), $this->admin, 20000, CommissionMode::Payout, scheme: DealScheme::Insurer);
        app(UpdateOffer::class)($this->offer->fresh(), ['floor_price' => 650000], $this->admin);
        $this->assertFalse(Offer::dealOpenTo($this->denis)->exists());

        app(ApplyReprice::class)($this->offer->fresh()->pendingReprice, $this->admin, 650000, [], 'ask', ['asking_price' => 1100000], showDays: 7);
        $offer = $this->offer->fresh();
        $this->assertTrue($offer->showsDealAsOpen());
        $this->assertSame(1100000, $offer->asking_price);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $offer->closesAt()->timestamp, 5);
        $this->assertTrue(Offer::siteList($this->denis)->whereKey($offer->id)->exists());
        Notification::assertSentTo($this->denis, DealOpenedNotice::class, fn (DealOpenedNotice $n) => str_starts_with($n->title(), 'Новое предложение'));
        Notification::assertNotSentTo($this->roman, DealOpenedNotice::class);
        // Денис подтверждает — резервом к идущей сделке.
        $reserve = app(PlaceBid::class)($offer, $this->denis, 1120000);
        $this->assertSame(BidState::Active, $reserve->state);
        // «Показ» из старой вкладки («Никому», «Пока идёт сделка») показ всем не затирает.
        app(UpdateOffer::class)($offer->fresh(), ['deal_audience_users' => [], 'deal_audience_groups' => [], 'deal_show_until' => null], $this->admin);
        $this->assertTrue($this->offer->fresh()->showsDealAsOpen());

        // Роман назвал цену — показ ещё идёт; приняли — машины в предложениях больше нет, резерв жив.
        $ask = app(AnswerReprice::class)->offer(RepriceAsk::where('deal_id', $deal->id)->sole(), 1150000, $this->roman);
        $this->assertTrue($this->offer->fresh()->showsDealAsOpen());
        app(AnswerReprice::class)->accept($ask, $this->admin);
        $this->assertFalse($this->offer->fresh()->showsDealAsOpen());
        $this->assertFalse(Offer::dealOpenTo($this->denis)->exists());
        $this->assertSame(BidState::Active, $reserve->fresh()->state);
        $this->assertSame(650000, $deal->fresh()->cost);
    }

    /** Отказался, пока показывали всем, — сделка отменена, приём идёт до конца показа. */
    public function test_declined_while_shown_to_all_keeps_intake_until_show_end(): void
    {
        // Готово к продаже: марка и кадр — иначе отказ увёл бы в черновики.
        $this->offer->update(['brand_id' => \App\Cars\Brand::create(['slug' => 'bmw', 'name' => 'BMW'])->id]);
        $this->offer->addMedia(\Illuminate\Http\UploadedFile::fake()->image('a.jpg', 800, 600))->toMediaCollection('photos');
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($this->offer, $this->roman, 1050000), $this->admin, 20000, CommissionMode::Payout, scheme: DealScheme::Insurer);
        app(UpdateOffer::class)($this->offer->fresh(), ['floor_price' => 650000], $this->admin);
        app(ApplyReprice::class)($this->offer->fresh()->pendingReprice, $this->admin, 650000, [], 'ask', showDays: 3);
        $until = $this->offer->fresh()->deal_show_until;

        app(AnswerReprice::class)->decline(RepriceAsk::where('deal_id', $deal->id)->sole(), $this->roman);
        $offer = $this->offer->fresh();
        $this->assertSame(DealState::Cancelled, $deal->fresh()->state);
        $this->assertSame(OfferState::Open, $offer->state);
        $this->assertSame($until->timestamp, $offer->bids_close_at->timestamp);
        $this->assertNull($offer->deal_show_all_at);
    }

    public function test_accepting_bid_with_open_question_moves_it_to_deal(): void
    {
        $bid = app(PlaceBid::class)($this->offer, $this->roman, 1050000);
        app(UpdateOffer::class)($this->offer->fresh(), ['floor_price' => 650000], $this->admin);
        app(ApplyReprice::class)($this->offer->fresh()->pendingReprice, $this->admin, 650000, [$bid->id => 'ask'], null);
        $ask = RepriceAsk::sole();
        $this->assertSame($bid->id, $ask->bid_id);

        // Приняли, пока Роман думал, — вопрос не гаснет, а переезжает на сделку; цена сделки до ответа прежняя.
        $deal = app(AcceptBid::class)($bid->fresh(), $this->admin, 20000, CommissionMode::Payout, scheme: DealScheme::Insurer);
        $ask->refresh();
        $this->assertSame([null, $deal->id], [$ask->bid_id, $ask->deal_id]);
        $this->assertTrue($ask->alive());
        $this->assertSame(594762, $deal->fresh()->cost);
    }

    /**
     * Спрашивает только админ, в любую сторону (03.11.2026, владелец: «автоматику убрать»): «Обновить» вверх — молча, без
     * вопроса; «Спросить» вниз — вопрос, цена до ответа прежняя.
     */
    public function test_admin_chooses_to_ask_either_way(): void
    {
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($this->offer, $this->roman, 1050000), $this->admin, 20000, CommissionMode::Payout, scheme: DealScheme::Insurer);
        app(UpdateOffer::class)($this->offer->fresh(), ['floor_price' => 650000], $this->admin);
        app(ApplyReprice::class)($this->offer->fresh()->pendingReprice, $this->admin, 650000, [], 'update');
        $this->assertSame(650000, $deal->fresh()->cost);
        $this->assertSame(0, RepriceAsk::count());
        Notification::assertNotSentTo($this->roman, RepriceAskNotice::class);

        app(UpdateOffer::class)($this->offer->fresh(), ['floor_price' => 600000], $this->admin);
        app(ApplyReprice::class)($this->offer->fresh()->pendingReprice, $this->admin, 600000, [], 'ask');
        $this->assertSame(650000, $deal->fresh()->cost);
        $ask = RepriceAsk::sole();
        $this->assertSame([650000, 600000], [$ask->from_price, $ask->to_price]);
        Notification::assertSentTo($this->roman, RepriceAskNotice::class, fn (RepriceAskNotice $n) => ! str_contains($n->title(), 'уменьшится'));
        $this->actingAs($this->roman)->post('http://xcar.localhost/reprice-asks/'.$ask->id, ['do' => 'price', 'price' => '1 050 000'])->assertRedirect();
        app(AnswerReprice::class)->accept($ask->fresh(), $this->admin);
        $this->assertSame(600000, $deal->fresh()->cost);
    }

    /**
     * Переигровка Альфы по X6 (03.11.2026): «Максимальная ставка 5500000,00» — новая оценочная (04.11.2026, владелец), закупочная —
     * плюс 5 % или 50 000, что меньше; переоценка на решение админу.
     */
    public function test_auction_top_bid_letter_opens_reprice(): void
    {
        $text = "Добрый день!\nВаше предложение в сумме 5189000,00 является не актуальным.\nМаксимальная ставка 5500000,00\nК рассмотрению принимаются предложения +5% или 50 000 руб. к максимальной ставке";
        $this->assertSame(5500000, Template::topBid($text));
        $this->assertNull(Template::newPrice($text));
        $this->offer->vendor->update(['floor_by_value' => true]);
        $this->offer->update(['claim_ref' => '0790/046/09068/26', 'floor_price' => 5189000]);
        $account = DB::table('mail_accounts')->insertGetId(['slug' => 'deal', 'title' => 'deal', 'email' => 'deal@xcar.ru', 'scope' => 'offers',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $message = Message::create(['account_id' => $account, 'direction' => 'in', 'from_email' => 'implementation@alfastrah.ru', 'subject' => 'RE: 0790/046/09068/26',
            'parsed' => ['keys' => ['code:'.Code::key('0790/046/09068/26')], 'own_text' => $text]]);

        $reprice = app(DetectReprice::class)($this->offer->fresh(), $message);
        $this->assertSame([5189000, 5550000, 5500000], [$reprice->from_price, $reprice->to_price, $reprice->value]);
        $this->assertSame(5189000, $this->offer->fresh()->floor_price);
        Notification::assertSentTo($this->admin, RepriceNotice::class);
        // До миллиона 5 % меньше 50 000 — плюс 5 %.
        $this->assertSame(840000, Pricing::rebid(800000));

        // Выбрали в шторке «+5%» — оценочная из письма встаёт и так.
        app(ApplyReprice::class)($reprice, $this->admin, 5775000, [], null);
        $this->assertSame([5775000, 5500000], [$this->offer->fresh()->floor_price, $this->offer->fresh()->value]);
    }

    /** Переоценка письмом в сделке — шаг «Переоценка»: цена → покупатель → ответ поставщику; ответили письмом — шаг ушёл. */
    public function test_reprice_step_in_deal_until_supplier_answered(): void
    {
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($this->offer, $this->roman, 1050000), $this->admin, 20000, CommissionMode::Payout, scheme: DealScheme::Insurer);
        $this->offer->update(['claim_ref' => '653253/2026']);
        $account = DB::table('mail_accounts')->insertGetId(['slug' => 'offer', 'title' => 'offer', 'email' => 'offer@xcar.ru', 'scope' => 'offers',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $letter = Message::create(['account_id' => $account, 'direction' => 'in', 'from_email' => 'korochkin@sovcomins.ru', 'subject' => 'RE: 653253/2026',
            'parsed' => ['keys' => ['code:'.Code::key('653253/2026')], 'own_text' => 'Новая стоимость – 650 000 руб.']]);
        $reprice = app(DetectReprice::class)($this->offer->fresh(), $letter);
        $this->assertNotNull($reprice);

        $flow = RepriceFlow::for($this->offer->fresh());
        $this->assertSame([RepriceFlow::NOW, RepriceFlow::WAIT, RepriceFlow::WAIT], [$flow->price(), $flow->buyer(), $flow->answer()]);

        app(ApplyReprice::class)($reprice->fresh(), $this->admin, 650000, [], 'ask');
        $flow = RepriceFlow::for($this->offer->fresh());
        $this->assertSame([RepriceFlow::DONE, RepriceFlow::NOW, RepriceFlow::WAIT], [$flow->price(), $flow->buyer(), $flow->answer()]);
        $this->assertNull($this->offer->fresh()->position(Track::Sale)->repriceUnanswered());

        // Роман назвал цену — решаем мы, ответ поставщику ждёт; приняли — пора ответить поставщику: «Ваш ход» и SQL-зеркало.
        $ask = app(AnswerReprice::class)->offer(RepriceAsk::where('deal_id', $deal->id)->sole(), 1100000, $this->roman);
        $this->assertSame(RepriceFlow::NOW, RepriceFlow::for($this->offer->fresh())->buyer());
        $this->assertNull($this->offer->fresh()->position(Track::Sale)->repriceUnanswered());
        app(AnswerReprice::class)->accept($ask, $this->admin);
        $flow = RepriceFlow::for($this->offer->fresh());
        $this->assertSame([RepriceFlow::DONE, RepriceFlow::DONE, RepriceFlow::NOW], [$flow->price(), $flow->buyer(), $flow->answer()]);
        $this->assertStringContainsString('650 000', $flow->replyText());
        $position = $this->offer->fresh()->position(Track::Sale);
        $this->assertSame('Ответить поставщику про переоценку', $position->todo()['title'] ?? null);
        $this->assertTrue(\App\Workflow\Position::whereKey($position->id)->needsUs()->exists());

        // Ответили письмом в ту же ветку — шаг ушёл.
        $sent = Message::create(['account_id' => $account, 'thread_id' => $letter->thread_id, 'direction' => 'out', 'from_email' => 'offer@xcar.ru', 'subject' => 'RE: 653253/2026']);
        Reprice::answeredBy($sent, $letter, $this->admin);
        $this->assertNotNull($reprice->fresh()->answered_at);
        $this->assertNull(RepriceFlow::for($this->offer->fresh()));
        $this->assertNull($this->offer->fresh()->position(Track::Sale)->repriceUnanswered());
    }

    /** Ответ Альфе — та же закупочная, что встаёт в сделку, с надбавкой к ставке словами (+50 000 или +5 %). */
    public function test_reply_to_alfa_names_our_price_and_rise(): void
    {
        $this->offer->vendor->update(['floor_by_value' => true]);
        $this->offer->update(['claim_ref' => '0790/046/09068/26', 'floor_price' => 5189000]);
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($this->offer->fresh(), $this->roman, 5600000), $this->admin, 20000, CommissionMode::Payout, scheme: DealScheme::Insurer);
        $account = DB::table('mail_accounts')->insertGetId(['slug' => 'deal', 'title' => 'deal', 'email' => 'deal@xcar.ru', 'scope' => 'offers',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $message = Message::create(['account_id' => $account, 'direction' => 'in', 'from_email' => 'implementation@alfastrah.ru', 'subject' => 'RE: 0790/046/09068/26',
            'parsed' => ['keys' => ['code:'.Code::key('0790/046/09068/26')], 'own_text' => 'Максимальная ставка 5500000,00. К рассмотрению принимаются предложения +5% или 50 000 руб. к максимальной ставке']]);
        $reprice = app(DetectReprice::class)($this->offer->fresh(), $message);
        app(ApplyReprice::class)($reprice, $this->admin, 5550000, [], 'update');

        $flow = RepriceFlow::for($this->offer->fresh());
        $this->assertSame([5550000, '+50 000 руб.'], [$flow->agreedAmount(), $flow->rise()]);
        $this->assertSame("Добрый день! Наше предложение — 5 550 000 руб. (максимальная ставка 5 500 000 руб. +50 000 руб.).\n\nС уважением,\nАндрей Кузнецов", str_replace("\u{A0}", ' ', $flow->replyText()));
        $this->assertSame(5550000, (int) $deal->fresh()->cost);
    }
}
