<?php

namespace Tests\Feature;

use App\Chats\Actions\PostMessage;
use App\Chats\Chat;
use App\Chats\GuestEnquiry;
use App\Chats\Jobs\SendGuestReply;
use App\Mail\Account;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * «Написать нам» гостем (13.10.2026, владелец: Антон не видел ответа — он был только в его браузере): почта для ответа
 * обязательна, ответ сотрудника уходит письмом с подписанной ссылкой, ссылка возвращает обращение в браузер.
 */
class GuestEnquiryMailTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'Админов Андрей', 'phone' => '79001234567', 'roles' => [Role::Admin], 'approved_at' => now()]);
    }

    public function test_guest_must_leave_email(): void
    {
        $this->post('http://xcar.localhost/contacts', ['name' => 'Антон', 'text' => 'Готов участвовать', 'consent' => 1])
            ->assertSessionHasErrors('email');
        $this->post('http://xcar.localhost/contacts', ['name' => 'Антон', 'email' => 'Ivan@Yandex.ru', 'text' => 'Готов участвовать', 'consent' => 1])
            ->assertRedirect();
        $this->assertSame('ivan@yandex.ru', Chat::first()->guest_email);
    }

    public function test_staff_reply_goes_by_mail_with_link_and_link_opens_chat(): void
    {
        Queue::fake([SendGuestReply::class]);
        $account = Account::create(['slug' => 'deal', 'title' => 'Сделки', 'email' => 'deal@xcar.test', 'scope' => 'offers',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $chat = Chat::create(['guest_name' => 'Антон', 'guest_email' => 'ivan@yandex.ru', 'guest_token' => hash('sha256', str_repeat('a', 40))]);
        $post = app(PostMessage::class);
        $post($chat, null, 'Готов участвовать в торгах');
        $reply = $post($chat->refresh(), $this->admin(), "Добрый вечер!\nНужна рекомендация");

        Queue::assertPushed(SendGuestReply::class, 1);
        $email = SendGuestReply::email($reply->load('chat'), $account);
        $this->assertSame('ivan@yandex.ru', $email->getTo()[0]->getAddress());
        $this->assertSame('deal@xcar.test', $email->getFrom()[0]->getAddress());
        $this->assertStringContainsString('Нужна рекомендация', $email->getHtmlBody());
        $this->assertStringContainsString('/contacts/open/'.$chat->id, $email->getHtmlBody());

        $link = URL::temporarySignedRoute('contacts.open', now()->addDay(), ['chat' => $chat->id]);
        $this->get($link)->assertRedirect('/contacts')->assertCookie(GuestEnquiry::COOKIE);
        $this->get('http://xcar.localhost/contacts/open/'.$chat->id)->assertForbidden();
    }

    public function test_auto_reply_and_account_chats_are_not_mailed(): void
    {
        Queue::fake([SendGuestReply::class]);
        $member = User::create(['name' => 'Петров Пётр', 'phone' => '79007654321', 'email' => 'p@x.test', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $mine = Chat::create(['user_id' => $member->id]);
        $admin = $this->admin();
        app(PostMessage::class)($mine, $admin, 'Ответ');
        $noMail = Chat::create(['guest_name' => 'Гость', 'guest_token' => hash('sha256', str_repeat('b', 40))]);
        app(PostMessage::class)($noMail, $admin, 'Ответ');

        Queue::assertNotPushed(SendGuestReply::class);
    }
}
