<?php

namespace Tests\Feature;

use App\Billing\Actions\RecordPayment;
use App\Billing\InvoiceState;
use App\Billing\PaymentSource;
use App\Mail\Extraction\Code;
use App\Mail\Extraction\Templates\Template;
use App\Mail\Message;
use App\Notifications\CostChangedNotice;
use App\Notifications\RepriceNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\ApplyReprice;
use App\Offers\Actions\DetectReprice;
use App\Offers\Actions\DismissReprice;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\UpdateOffer;
use App\Offers\CommissionMode;
use App\Offers\DealScheme;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Reprice;
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
        $list = $this->actingAs($this->admin)->get('http://crm.xcar.localhost/?preset=bids&vid=table&peek='.$this->offer->number)->assertOk()->getContent();
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
        Notification::assertSentTo($this->roman, CostChangedNotice::class);
        Notification::assertNotSentTo($this->denis, CostChangedNotice::class);
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
        Notification::assertNotSentTo($this->roman, CostChangedNotice::class);

        // «Обновить» — закупочная и сумма страховой новые, неоплаченный подбор перевыставлен.
        app(UpdateOffer::class)($this->offer->fresh(), ['floor_price' => 433200], $this->admin);
        app(ApplyReprice::class)($this->offer->fresh()->pendingReprice, $this->admin, 433200, [], 'update');
        $deal = $deal->fresh();
        $this->assertSame([433200, null, 433200], [$deal->cost, $deal->supplier_cost, (int) $deal->owner_price]);
        $this->assertSame(InvoiceState::Void, $first->fresh()->state);
        $this->assertEqualsWithDelta(1050000 - 433200 - 20000, $deal->issuedInvoices()->where('state', InvoiceState::Issued)->sole()->total, 0.01);
        Notification::assertSentTo($this->roman, CostChangedNotice::class);

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
}
