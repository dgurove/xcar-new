<?php

namespace Tests\Feature;

use App\Mail\Events\MessageParsed;
use App\Mail\Events\MessageSent;
use App\Mail\Message;
use App\Mail\Thread;
use App\Notifications\DealInfoNotice;
use App\Notifications\SupplierInvoiceArrivedNotice;
use App\Notifications\YourTurnNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\Deal;
use App\Offers\DealScheme;
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
/**
 * Показанное менеджеру правится и удаляется (25.11.2026, владелец по 779371/2026: «должна быть возможность редактировать: в тексте
 * убрать или добавить, документ убрать или добавить; при сохранении в сделке поменялось, то сообщение удалилось и новое отправилось»).
 * И текст письма идёт менеджеру без обращения к нам («Добрый день Андрей.Катерина, просьба связать с клиентом…»).
 */
class DisclosureEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private Offer $offer;

    private Deal $deal;

    private int $account;

    private Thread $thread;

    public function test_letter_text_without_greeting_and_requests_to_us(): void
    {
        $this->deal();
        $m = $this->letter("Добрый день Андрей .Катерина ,\r\n\r\nпросьба связать с клиентом по вывозу ГОТС\r\n\r\nК передаче ГОТС готовы.\r\n\r\nТелефон для связи: 89511584912 - Дмитрий.\r\n");
        $this->assertSame("К передаче ГОТС готовы.\nТелефон для связи: 89511584912 - Дмитрий.", \App\Offers\Disclosures::letterText($m));
        $pay = $this->letter("Добрый день! Просим оплатить счёт до 28.11.\r\nСчёт во вложении.\r\n");
        $this->assertSame("Просим оплатить счёт до 28.11.\nСчёт во вложении.", \App\Offers\Disclosures::letterText($pay));
    }

    public function test_edit_and_delete_disclosure(): void
    {
        $this->deal();
        $offer = $this->offer->fresh();
        $a = $offer->addMediaFromString('%PDF-1.4')->usingFileName('акт.pdf')->toMediaCollection('papers');
        $b = $offer->addMediaFromString('%PDF-1.4')->usingFileName('договор.pdf')->toMediaCollection('papers');
        $c = $offer->addMediaFromString('%PDF-1.4')->usingFileName('паспорт.pdf')->toMediaCollection('papers');
        $this->actingAs($this->admin)->post("http://crm.xcar.localhost/work/deals/{$this->deal->id}/disclose", [
            'card' => 1, 'on' => ['file:'.$a->id, 'file:'.$b->id], 'text' => 'Добрый день Андрей, просьба связать',
            'contact_name' => 'Дмитрий', 'contact_phone' => '89511584912',
        ])->assertSessionHasNoErrors();
        $disclosure = \App\Offers\Disclosure::where('deal_id', $this->deal->id)->sole();
        $first = \App\Chats\Message::findOrFail($disclosure->chat_message_id);
        $this->assertStringContainsString('просьба связать', $first->text);

        Notification::fake();
        $this->put("http://crm.xcar.localhost/work/deals/{$this->deal->id}/disclosures/{$disclosure->id}", [
            'text' => 'К передаче готовы', 'name' => ['Дмитрий'], 'phone' => ['8 951 158-49-12'], 'files' => [$a->id, $c->id],
        ])->assertSessionHasNoErrors()->assertSessionHas('toast', 'Исправлено, менеджеру ушло заново');

        $disclosure->refresh();
        $this->assertNotNull($first->fresh()->deleted_at);
        // Удалённое не уходит уведомлением, даже если оно ещё ждало в очереди.
        $this->assertFalse((new \App\Notifications\ChatNotice($first, false))->shouldSend($this->manager, \App\Notifications\TelegramChannel::class));
        $second = \App\Chats\Message::findOrFail($disclosure->chat_message_id);
        $this->assertNotSame($first->id, $second->id);
        $this->assertStringContainsString('К передаче готовы', $second->text);
        $this->assertStringNotContainsString('просьба', $second->text);
        $this->assertStringContainsString('+7 951 158-49-12', $second->text);
        $this->assertSame(2, $second->files()->count());
        Notification::assertSentTo($this->manager, \App\Notifications\ChatNotice::class);
        $this->actingAs($this->manager)->get("http://xcar.localhost/files/{$b->id}")->assertNotFound();
        $this->get("http://xcar.localhost/files/{$c->id}")->assertOk();
        $this->page()->assertSee('К передаче готовы')->assertDontSee('просьба связать');

        // Неверный телефон — ошибка поля, показ прежний.
        $this->actingAs($this->admin)->put("http://crm.xcar.localhost/work/deals/{$this->deal->id}/disclosures/{$disclosure->id}", ['phone' => ['123']])
            ->assertSessionHasErrors('phone.0');

        // Удалить — показа нет, сообщения нет, документы скрыты.
        $this->delete("http://crm.xcar.localhost/work/deals/{$this->deal->id}/disclosures/{$disclosure->id}")->assertSessionHas('toast', 'Показ удалён');
        $this->assertNull(\App\Offers\Disclosure::find($disclosure->id));
        $this->assertNotNull($second->fresh()->deleted_at);
        $this->actingAs($this->manager)->get("http://xcar.localhost/files/{$a->id}")->assertNotFound();
        $this->page()->assertDontSee('К передаче готовы');

        // Чужой показ — 404.
        $other = \App\Offers\Disclosure::create(['deal_id' => $this->deal->id, 'items' => [['kind' => 'text', 'value' => 'x']], 'created_by' => $this->admin->id]);
        $this->actingAs($this->admin)->delete('http://crm.xcar.localhost/work/deals/999999/disclosures/'.$other->id)->assertNotFound();
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
