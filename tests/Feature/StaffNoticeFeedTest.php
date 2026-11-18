<?php

namespace Tests\Feature;

use App\Mail\Events\MessageSent;
use App\Mail\Message;
use App\Mail\OnMessage;
use App\Mail\Thread;
use App\Notifications\Batch;
use App\Notifications\DealEventNotice;
use App\Notifications\Delivery;
use App\Notifications\SupplierMailNotice;
use App\Notifications\TelegramChannel;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\DealScheme;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Push\WebPushChannel;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Админам — всё по сделкам в ленту, пушем и в Telegram (18.11.2026: начальник не получил «ГОТС поступили на реализацию» в 15:55 —
 * письмо поставщика уходило только Telegram-сообщением мимо ленты, пуши — только на подписки CRM, которых у него нет). Ошибка
 * молчаливая и дорогая: уведомление просто не придёт. По умолчанию у админа всё включено; своих действий нет; выключенная
 * категория — никуда, и это видно в журнале; выключен Telegram категории — лента и пуш остаются; каждое событие — своей строкой.
 */
class StaffNoticeFeedTest extends TestCase
{
    use RefreshDatabase;

    private User $andrey;

    private User $dmitry;

    private Offer $offer;

    private int $account;

    private Thread $thread;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['xcar.mercure.hub' => '']);
        $this->andrey = tap(User::create(['name' => 'Андрей Кузнецов', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]))->forceFill(['telegram_chat_id' => 101])->save() ? User::where('phone', '79000000001')->first() : null;
        $this->dmitry = tap(User::create(['name' => 'Дмитрий Гуров', 'phone' => '79000000003', 'roles' => [Role::Admin], 'approved_at' => now()]))->forceFill(['telegram_chat_id' => 103])->save() ? User::where('phone', '79000000003')->first() : null;
        $manager = User::create(['name' => 'Роман Корабельников', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->vendor = Vendor::firstOrCreate(['name' => 'АльфаСтрахование']);
        app(ApplyPreset::class)($this->vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $this->offer = Offer::create(['vendor_id' => $this->vendor->id, 'claim_ref' => '0399/046/02681/26', 'floor_price' => 900000, 'asking_price' => 1000000, 'bids_close_at' => now()->addDay()]);
        $this->offer->forceFill(['state' => OfferState::Open])->save();
        $this->offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($this->offer, Stage::where('workflow_id', $this->vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        Notification::fake();
        app(AcceptBid::class)(app(PlaceBid::class)($this->offer, $manager, 1000000), $this->andrey, scheme: DealScheme::Prime);
        $this->account = DB::table('mail_accounts')->insertGetId(['slug' => 'deal', 'title' => 'deal', 'email' => 'deal@xcar.ru', 'scope' => 'offers', 'is_active' => true,
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $this->thread = Thread::create(['account_id' => $this->account, 'subject' => '0399/046/02681/26', 'offer_id' => $this->offer->id, 'vendor_id' => $this->vendor->id, 'last_message_at' => now()]);
    }

    private function supplierLetter(string $text): Message
    {
        return Message::create(['account_id' => $this->account, 'thread_id' => $this->thread->id, 'direction' => 'in', 'message_id' => uniqid().'@alfastrah.ru',
            'from_email' => 'implementation@alfastrah.ru', 'from_name' => 'Реализация', 'subject' => 'RE: 0399/046/02681/26', 'text_body' => $text, 'date_at' => now()->addMinute()]);
    }

    private function arrives(Message $letter): void
    {
        $message = $letter->fresh(['account', 'thread']);
        $offer = $this->offer->fresh();
        (fn () => $this->toStaff($message, $offer))->call(app(OnMessage::class));
    }

    /** Все каналы: лента, пуш, Telegram. */
    private function everywhere(): \Closure
    {
        return fn ($n, array $channels) => in_array('database', $channels, true) && in_array(WebPushChannel::class, $channels, true) && in_array(TelegramChannel::class, $channels, true);
    }

    public function test_supplier_letter_reaches_every_admin_everywhere(): void
    {
        $this->arrives($this->supplierLetter('Добрый день! ГОТС поступили на реализацию. Подтвердить актуальность предложения необходимо до переигровки.'));

        foreach ([$this->andrey, $this->dmitry] as $admin) {
            Notification::assertSentTo($admin, SupplierMailNotice::class, $this->everywhere());
        }
        Notification::assertSentTo($this->andrey, SupplierMailNotice::class, function (SupplierMailNotice $n) {
            return $n->car() === $this->offer->titleWithYear() && $n->brief() === 'АльфаСтрахование: ГОТС поступили на реализацию'
                && $n->vendor()?->is($this->vendor) && $n->important();
        });
        // Автоответ — не письмо поставщика.
        Notification::fake();
        $auto = $this->supplierLetter('Я отсутствую на рабочем месте до 20.11.');
        $auto->update(['subject' => 'Автоматический ответ: RE: 0399/046/02681/26']);
        $this->arrives($auto);
        Notification::assertNothingSent();
    }

    public function test_deal_events_skip_the_author(): void
    {
        // Сделку открыл Андрей — Дмитрию строкой, Андрею нет.
        Notification::assertSentTo($this->dmitry, DealEventNotice::class, fn (DealEventNotice $n, array $c) => $n->kind() === 'DealEventNotice:opened' && $this->everywhere()($n, $c));
        Notification::assertNotSentTo($this->andrey, DealEventNotice::class, fn (DealEventNotice $n) => $n->kind() === 'DealEventNotice:opened');
        // Подтверждение Романа — обоим.
        Notification::assertSentTo($this->andrey, DealEventNotice::class, fn (DealEventNotice $n) => $n->kind() === 'DealEventNotice:bid' && str_starts_with($n->brief(), 'Роман Корабельников: подтверждает 1'));

        // Андрей написал поставщику — пачкой (десять писем подряд — одна строка), Дмитрию.
        $letter = Message::create(['account_id' => $this->account, 'thread_id' => $this->thread->id, 'direction' => 'out', 'message_id' => uniqid().'@xcar.ru',
            'from_email' => 'deal@xcar.ru', 'subject' => 'RE: 0399/046/02681/26', 'text_body' => 'Подтверждаем актуальность', 'date_at' => now(), 'sent_at' => now(), 'created_by' => $this->andrey->id]);
        Notification::fake();
        MessageSent::dispatch($letter);
        $this->travel(3)->minutes();
        Batch::flush();
        Notification::assertSentTo($this->dmitry, DealEventNotice::class, fn (DealEventNotice $n) => $n->kind() === 'DealEventNotice:our-letter'
            && $n->brief() === 'Андрей Кузнецов: письмо поставщику');
        Notification::assertNotSentTo($this->andrey, DealEventNotice::class);
    }

    public function test_switched_off_category_is_journaled_and_telegram_toggle_keeps_feed(): void
    {
        $this->dmitry->update(['notification_settings' => ['off' => ['supplier_mail']]]);
        $this->andrey->update(['notification_settings' => ['telegram_off' => ['supplier_mail']]]);
        $this->arrives($this->supplierLetter('Добрый день! Денежные средства на счету компании.'));

        Notification::assertNotSentTo($this->dmitry, SupplierMailNotice::class);
        $this->assertTrue(Delivery::where('user_id', $this->dmitry->id)->where('status', 'off')->where('detail', 'категория выключена')->exists());
        Notification::assertSentTo($this->andrey, SupplierMailNotice::class, fn ($n, array $c) => in_array('database', $c, true)
            && in_array(WebPushChannel::class, $c, true) && ! in_array(TelegramChannel::class, $c, true));
        $this->assertTrue(Delivery::where('user_id', $this->andrey->id)->where('channel', 'telegram')->where('detail', 'выключено в категории')->exists());
    }

    public function test_each_event_is_its_own_feed_row(): void
    {
        $deal = $this->offer->fresh()->deal->load(['offer', 'buyer']);
        foreach ([DealEventNotice::dealOpened($deal, $this->andrey), DealEventNotice::disclosed($deal, $this->andrey)] as $notice) {
            $notice->id = (string) \Illuminate\Support\Str::uuid();
            $row = app(\Illuminate\Notifications\Channels\DatabaseChannel::class)->send($this->dmitry, $notice);
            event(new \Illuminate\Notifications\Events\NotificationSent($this->dmitry, $notice, 'database', $row));
        }
        $this->assertSame(2, $this->dmitry->notifications()->count());
        $row = $this->dmitry->notifications()->latest()->first();
        $this->assertSame($this->offer->titleWithYear(), $row->data['car']);
        $this->assertSame($this->andrey->id, $row->data['actor']);
    }
}
