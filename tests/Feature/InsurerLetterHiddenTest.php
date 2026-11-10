<?php

namespace Tests\Feature;

use App\Mail\Events\MessageParsed;
use App\Mail\Message;
use App\Mail\Thread;
use App\Notifications\DealInfoNotice;
use App\Notifications\YourTurnNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\Deal;
use App\Offers\DealScheme;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\ReplyReview;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Position;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Письмо страховой — менеджеру ничего, пока админ не выберет (03.11.2026, владелец: «пришло письмо с телефоном и паспортом
 * хозяина — менеджеру вообще ничего не показываем и не говорим даже, что пришёл ответ»; «дыр не должно быть вообще»). Сделка
 * встаёт в «Разобрать ответ», админ показывает отмеченное (правленое) — менеджер видит ровно это. Утечка тут молчалива и дорога.
 */
class InsurerLetterHiddenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private Offer $offer;

    private Deal $deal;

    private int $account;

    private Thread $thread;

    public function test_manager_sees_only_what_admin_shows(): void
    {
        $this->deal();
        $this->offer->update(['insured_name' => 'Петров Пётр', 'insured_phone' => '79005556677', 'inspection_address' => 'ул. Ленина, 5']);
        $stage = fn () => $this->offer->fresh()->position(Track::Sale)->stage->name;
        $before = $stage();
        $paper = $this->offer->fresh()->addMediaFromString('%PDF-1.4')->usingFileName('паспорт.pdf')->toMediaCollection('papers');

        $letter = $this->letter("Добрый день! Собственник Иванов Иван, +7 (900) 123-45-67, цена 5189000.\r\nГорячая линия 8-800-333-0-999\r\n");
        $paper->setCustomProperty('letter', $letter->id)->save();

        // Менеджеру — ничего: ни уведомления, ни шага, ни телефона, ни имени, ни адреса, ни «Ждём XCar».
        Notification::assertNothingSentTo($this->manager);
        $this->assertSame($before, $stage());
        $this->page()->assertDontSee('123-45-67')->assertDontSee('Иванов')->assertDontSee('5189000')->assertDontSee('Информация по сделке')
            ->assertDontSee('Петров')->assertDontSee('555-66-77')->assertDontSee('Ленина');
        $this->get("http://xcar.localhost/files/{$paper->id}")->assertNotFound();

        // Админ — в «Разобрать ответ», видит письмо и пункты.
        $this->assertSame(1, ReplyReview::pending()->where('deal_id', $this->deal->id)->count());
        $this->assertSame('Разобрать ответ', $this->offer->fresh()->position(Track::Sale)->todo()['title']);
        $this->actingAs($this->admin)->withHeaders(['Turbo-Frame' => 'detail'])->get("http://crm.xcar.localhost/work/deals?peek={$this->deal->id}")->assertOk()
            ->assertSee('Письмо от Альфа тест')->assertSee('Иванов Иван')->assertSee('+7 900 123-45-67')->assertSee('паспорт.pdf')
            ->assertSee('Пройти шаг «Поставщик согласовал»');

        // Показать: телефон, имя «Пётр» вместо «Петров Пётр», паспорт, своё сообщение и шаг.
        // Шаг своей машины, а не первый с таким именем: «Письмо о покупке» есть в нескольких маршрутах.
        $exit = $this->offer->fresh()->stage()->exits()->where('label', 'Поставщик согласовал')->firstOrFail();
        $this->post("http://crm.xcar.localhost/work/deals/{$this->deal->id}/disclose", [
            'on' => ['phone:79001234567', 'name:'.md5('петров пётр'), 'file:'.$paper->id],
            'value' => ['phone:79001234567' => '+7 900 123-45-67', 'name:'.md5('петров пётр') => 'Пётр', 'phone:79005556677' => 'не отмечен'],
            'text' => 'Звонить после 12', 'step' => $exit->id,
        ])->assertSessionHasNoErrors();

        Notification::assertSentToTimes($this->manager, DealInfoNotice::class, 1);
        Notification::assertSentTo($this->manager, DealInfoNotice::class, fn (DealInfoNotice $n) => str_contains($n->text(), '+7 900 123-45-67')
            && str_contains($n->text(), 'Пётр') && ! str_contains($n->text(), 'Петров') && ! str_contains($n->text(), '555-66-77')
            && str_contains($n->text(), 'Звонить после 12') && str_contains($n->text(), 'Документы: 1') && str_contains($n->text(), 'Ваш ход'));
        Notification::assertNotSentTo($this->manager, YourTurnNotice::class);
        $this->assertSame('Согласие менеджера', $stage());
        $this->assertSame(0, ReplyReview::pending()->count());
        $this->page()->assertSee('Информация по сделке')->assertSee('+7 900 123-45-67')->assertSee('Пётр')->assertSee('Звонить после 12')
            ->assertSee('паспорт.pdf')->assertDontSee('Петров')->assertDontSee('555-66-77')->assertDontSee('Ленина')
            ->assertDontSee('Иванов')->assertDontSee('5189000');
        $this->get("http://xcar.localhost/files/{$paper->id}")->assertOk();
    }

    public function test_nothing_shown_and_no_foreign_items(): void
    {
        $this->deal();
        $this->letter("Добрый день! Контакт +7 900 765-43-21\r\n");

        // Чужой документ и выдуманный пункт не проходят.
        $other = Offer::create(['vendor_id' => $this->offer->vendor_id]);
        $foreign = $other->addMediaFromString('%PDF-1.4')->usingFileName('чужой.pdf')->toMediaCollection('papers');
        $this->actingAs($this->admin)->post("http://crm.xcar.localhost/work/deals/{$this->deal->id}/disclose", ['on' => ['file:'.$foreign->id]])
            ->assertSessionHasErrors('disclose');
        $this->post("http://crm.xcar.localhost/work/deals/{$this->deal->id}/disclose", ['on' => ['text:x'], 'value' => ['text:x' => 'Закупочная 5189000']])
            ->assertSessionHasErrors('disclose');

        // «Ничего не показывать» — разобрано, менеджеру ничего.
        $this->post("http://crm.xcar.localhost/work/deals/{$this->deal->id}/reviews/done")->assertRedirect();
        $this->assertSame(0, ReplyReview::pending()->count());
        Notification::assertNothingSentTo($this->manager);
        $this->page()->assertDontSee('765-43-21')->assertDontSee('Информация по сделке');

        // Неразобранное письмо отменённой сделки машину в «Разобрать ответ» не держит.
        $this->letter("Добрый день! Ещё контакт +7 900 765-43-22\r\n");
        $this->assertSame('Разобрать ответ', $this->offer->fresh()->position(Track::Sale)->todo()['title'] ?? null);
        $this->deal->forceFill(['state' => DealState::Cancelled])->save();
        $this->assertNotSame('Разобрать ответ', $this->offer->fresh()->position(Track::Sale)?->todo()['title'] ?? null);
        $this->assertFalse(Position::where('offer_id', $this->offer->id)->where('track', 'sale')->whereRaw("exists (select 1 from reply_reviews rr join deals rd on rd.id = rr.deal_id where rr.offer_id = offer_positions.offer_id and rr.state = 'pending' and rd.state = 'active')")->exists());
    }

    /** Сотрудник сдвинул шаг после письма — разбор закрыт, в «Истории» строка (10.11.2026, Volvo 01527); шаг менеджера — нет. */
    public function test_staff_step_closes_review(): void
    {
        $this->deal();
        $this->letter("Добрый день! Согласовано.\r\n");
        $exit = $this->offer->fresh()->stage()->exits()->where('label', 'Поставщик согласовал')->firstOrFail();
        $this->actingAs($this->admin)->post("http://crm.xcar.localhost/offers/".$this->offer->fresh()->number."/exit/{$exit->id}")->assertRedirect();
        $this->assertSame(0, ReplyReview::pending()->where('deal_id', $this->deal->id)->count());
        $this->assertSame($this->admin->id, ReplyReview::where('deal_id', $this->deal->id)->value('done_by'));
        $this->assertTrue($this->offer->events()->where('payload->text', 'Письмо разобрано шагом «Поставщик согласовал»')->exists());
    }

    private function deal(): void
    {
        Notification::fake();
        config(['xcar.mercure.hub' => '']);
        $this->admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Владислав', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $this->offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 5189000, 'asking_price' => 5600000, 'bids_close_at' => now()->addDay()]);
        $this->offer->forceFill(['state' => OfferState::Open])->save();
        $this->offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($this->offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $this->deal = app(AcceptBid::class)(app(PlaceBid::class)($this->offer, $this->manager, 5600000), $this->admin, scheme: DealScheme::Prime);
        $this->account = DB::table('mail_accounts')->insertGetId(['slug' => 'deal', 'title' => 'deal', 'email' => 'deal@xcar.ru', 'scope' => 'offers', 'is_active' => true,
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $this->thread = Thread::create(['account_id' => $this->account, 'subject' => '0790/046/09068/26', 'offer_id' => $this->offer->id, 'last_message_at' => now()]);
        Notification::fake();
    }

    /** Счёт страховой — громко админам (10.11.2026, `SupplierInvoiceArrivedNotice`); прочие ответы — тихо. */
    public function test_invoice_letter_alerts_admins(): void
    {
        $this->deal();
        $this->letter("Добрый день! Контакт +7 900 765-43-21\r\n");
        Notification::assertNotSentTo($this->admin, \App\Notifications\SupplierInvoiceArrivedNotice::class);
        $this->letter("Добрый день! Внимание!!! Оплата принимается только от плательщика по счету!!!\r\n");
        Notification::assertSentTo($this->admin, \App\Notifications\SupplierInvoiceArrivedNotice::class, fn ($n) => $n->important() && str_contains($n->title(), 'выставила счёт'));
    }

    /** Письмо страховой настоящим путём: разобрано ящиком — `MessageParsed` → `OnMessage` (история, файлы, «Разобрать ответ»). */
    private function letter(string $body): Message
    {
        $message = Message::create(['account_id' => $this->account, 'thread_id' => $this->thread->id, 'direction' => 'in',
            'from_email' => 'implementation@alfastrah.ru', 'from_name' => 'implementation', 'subject' => 'RE: 0790/046/09068/26',
            'date_at' => now()->addMinute(), 'text_body' => $body]);
        MessageParsed::dispatch($message);

        return $message;
    }

    private function page()
    {
        return $this->actingAs($this->manager)->get("http://xcar.localhost/deals/{$this->deal->id}")->assertOk();
    }
}
