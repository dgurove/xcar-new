<?php

namespace Tests\Feature;

use App\Notifications\Categories;
use App\Notifications\Notice;
use App\Notifications\SystemMailChannel;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Почта по согласию (06.11.2026, владелец: «только СУПЕР важное, все ненавидят спам»): уведомление письмом — только из
 * включённых человеком категорий `Categories::mail`, на подтверждённую почту, и только где нужен человек. Ошибка
 * молчалива и дорога: включили SMTP — и сотни писем полетели менеджерам; домен уходит в спам.
 */
class SystemMailTest extends TestCase
{
    use RefreshDatabase;

    private function notice(string $category, bool $important = true): Notice
    {
        return new class($category, $important) extends Notice
        {
            public function __construct(private string $cat, private bool $imp) {}

            public function title(): string { return 'Ваш ход: Kia Rio'; }

            public function label(): string { return 'Тест'; }

            public function href(): string { return '/deals/1'; }

            public function category(): string { return $this->cat; }

            public function important(): bool { return $this->imp; }
        };
    }

    public function test_mail_only_for_opted_verified_important(): void
    {
        $u = User::create(['name' => 'Денис', 'phone' => '79000000002', 'email' => 'd@example.com', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $has = fn (Notice $n) => in_array(SystemMailChannel::class, $n->via($u->fresh()), true);

        $this->assertFalse($has($this->notice('deals')), 'по умолчанию на почту ничего');
        $u->update(['notification_settings' => ['mail_on' => ['deals', 'chats', 'offers']]]);
        $this->assertFalse($has($this->notice('deals')), 'почта не подтверждена');
        $u->forceFill(['email_verified_at' => now()])->save();
        $this->assertTrue($has($this->notice('deals')));
        $this->assertFalse($has($this->notice('deals', important: false)), 'не важное — не письмом');
        $this->assertFalse($has($this->notice('chats')), 'чаты на почту не бывают');
        $this->assertFalse($has($this->notice('offers')), 'новые предложения на почту не бывают');
        $this->assertArrayNotHasKey('chats', Categories::mail($u));

        // Сменил почту — подтверждать заново.
        $u->update(['email' => 'new@example.com']);
        $this->assertNull($u->fresh()->email_verified_at);
        $this->assertFalse($has($this->notice('deals')));
    }

    public function test_letter_headers_unsubscribe_one_click(): void
    {
        $u = User::create(['name' => 'Денис', 'phone' => '79000000002', 'email' => 'd@example.com', 'roles' => [Role::Manager], 'approved_at' => now(),
            'email_verified_at' => now(), 'notification_settings' => ['mail_on' => ['deals']]]);
        (new SystemMailChannel)->send($u, $this->notice('deals'));
        $sent = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame('Ваш ход: Kia Rio', $sent->getSubject());
        $this->assertSame('auto-generated', $sent->getHeaders()->get('Auto-Submitted')->getBodyAsString());
        $this->assertStringContainsString('/mail/unsubscribe/', $sent->getHeaders()->get('List-Unsubscribe')->getBodyAsString());

        // Почтовик отписывает POST-ом без токена — это отписка, а не «Вернуть».
        $this->post($u->unsubscribeUrl(), ['List-Unsubscribe' => 'One-Click'])->assertNoContent();
        $this->assertFalse($u->fresh()->wantsMail());
        $this->assertFalse($u->fresh()->wantsMailFor('deals'));
    }

    public function test_verify_link_and_security_letters(): void
    {
        Queue::fake();
        $u = User::create(['name' => 'Денис', 'phone' => '79000000002', 'email' => 'd@example.com', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->actingAs($u)->post('/account/notifications/verify-mail')->assertSessionHas('toast', 'Письмо отправлено на d@example.com');
        Queue::assertPushed(\App\Mail\Jobs\SendSystemMail::class, 1);
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('mail.verify', now()->addDay(), ['user' => $u->id, 'hash' => sha1('d@example.com')]);
        $this->get($url)->assertOk()->assertSee('Почта подтверждена');
        $this->assertTrue($u->fresh()->mailVerified());

        // Пароль сменили — письмо; почту сменили — прежнему адресу письмо.
        $u->fresh()->forceFill(['password' => 'new-secret-1'])->save();
        $u->fresh()->update(['email' => 'other@example.com']);
        Queue::assertPushed(\App\Mail\Jobs\SendSystemMail::class, 3);
    }
}
