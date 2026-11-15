<?php

namespace Tests\Feature;

use App\Mail\Actions\DropFailed;
use App\Mail\Message;
use App\Mail\SendError;
use App\Support\Surface;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «Отправить» ждёт настоящей отправки в редакторе (15.11.2026, начальник не был уверен, что письмо ушло): редактор узнаёт
 * ответ `messages/{id}/sent` с `X-Compose-Send` и ждёт `send_state = sent` по базе; «не ушло» — причиной словами, повторная
 * отправка заменяет неудачное письмо (`DropFailed`). Без заголовка — прежний ответ окна.
 */
class MailSendWaitTest extends TestCase
{
    use RefreshDatabase;

    public function test_state_follows_database_and_failure_is_in_words(): void
    {
        $admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $account = DB::table('mail_accounts')->insertGetId(['slug' => 'deal', 'title' => 'deal', 'email' => 'deal@xcar.ru', 'scope' => 'offers',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $message = Message::create(['account_id' => $account, 'direction' => 'out', 'from_email' => 'deal@xcar.ru', 'subject' => 'AUT-26-920056',
            'send_state' => 'queued', 'created_by' => $admin->id, 'date_at' => now()]);

        $this->actingAs($admin)->get(Surface::Crm->url("/work/mail/messages/{$message->id}/sent"), ['X-Compose-Send' => '1'])
            ->assertOk()->assertJson(['id' => $message->id, 'state' => 'queued']);
        $this->actingAs($admin)->get(Surface::Crm->url("/work/mail/messages/{$message->id}/sent?then=close"))
            ->assertRedirect(Surface::Crm->url("/work/mail/{$message->thread_id}/window"))->assertSessionHas('letters-close', true);
        $this->actingAs($admin)->getJson(Surface::Crm->url("/work/mail/messages/{$message->id}/state"))->assertOk()->assertJson(['state' => 'queued']);

        $message->forceFill(['send_state' => 'sent', 'sent_at' => now()])->save();
        $this->actingAs($admin)->getJson(Surface::Crm->url("/work/mail/messages/{$message->id}/state"))->assertJson(['state' => 'sent']);

        // Не ушло — повторная отправка из того же редактора убирает неудачное, файлы outbox не трогает.
        $failed = Message::create(['account_id' => $account, 'direction' => 'out', 'from_email' => 'deal@xcar.ru', 'subject' => 'AUT-26-920056',
            'send_state' => 'failed', 'send_error' => '550 no such user', 'created_by' => $admin->id, 'date_at' => now()]);
        $other = User::create(['name' => 'Иван', 'phone' => '79000000002', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->assertNull(app(DropFailed::class)($failed->id, $other));
        $this->assertNull(app(DropFailed::class)($message->id, $admin));
        app(DropFailed::class)($failed->id, $admin);
        $this->assertNull(Message::find($failed->id));
        $this->assertNotNull(Message::find($message->id));

        $this->assertSame('Адрес получателя не существует или закрыт, проверьте «Кому»', SendError::words('Expected response code 250 but got 550: Mailbox unavailable'));
    }
}
