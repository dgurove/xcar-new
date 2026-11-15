<?php

namespace Tests\Feature;

use App\Mail\Message;
use App\Mail\SendError;
use App\Support\Surface;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Экран отправки письма (15.11.2026, начальник не был уверен, что письмо ушло): «Отправляем» → «Письмо ушло» только по базе
 * (`send_state = sent`), «Не ушло» — причиной словами.
 */
class MailSentScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_state_follows_database_and_failure_is_in_words(): void
    {
        $admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $account = DB::table('mail_accounts')->insertGetId(['slug' => 'deal', 'title' => 'deal', 'email' => 'deal@xcar.ru', 'scope' => 'offers',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $message = Message::create(['account_id' => $account, 'direction' => 'out', 'from_email' => 'deal@xcar.ru', 'subject' => 'AUT-26-920056',
            'send_state' => 'queued', 'created_by' => $admin->id, 'date_at' => now()]);

        $this->actingAs($admin)->get(Surface::Crm->url("/work/mail/messages/{$message->id}/sent"))->assertOk()->assertSee('Отправляем');
        $this->actingAs($admin)->getJson(Surface::Crm->url("/work/mail/messages/{$message->id}/state"))->assertOk()->assertJson(['state' => 'queued']);

        $message->forceFill(['send_state' => 'sent', 'sent_at' => now()])->save();
        $this->actingAs($admin)->getJson(Surface::Crm->url("/work/mail/messages/{$message->id}/state"))->assertJson(['state' => 'sent']);

        $this->assertSame('Адрес получателя не существует или закрыт, проверьте «Кому»', SendError::words('Expected response code 250 but got 550: Mailbox unavailable'));
    }
}
