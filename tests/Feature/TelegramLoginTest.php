<?php

namespace Tests\Feature;

use App\Notifications\TelegramChannel;
use App\Support\Settings;
use App\Support\Surface;
use App\Users\Access\Device;
use App\Users\Access\Lock;
use App\Users\Access\TelegramEntry;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Кнопка уведомления в Telegram входит сама (21.10.2026, владелец: «из Telegram без входа»): подпись Telegram — вход, один
 * раз; путь только свой; при «одном устройстве» прежнее устройство (приложение на экране «Домой») не выходит, а сам вход
 * живёт 12 часов. Ошибка здесь молчалива — вход под чужим именем или выкинутое приложение, — поэтому тест.
 */
class TelegramLoginTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['db']->extend('pgsql_async', fn () => $this->app['db']->connection('pgsql'));
        config(['xcar.telegram.token' => 'test-token', 'xcar.telegram.login' => true, 'xcar.mercure.hub' => '']);
        Settings::put(Lock::SETTING, false);
        Settings::put(Lock::SINGLE, true);
        $this->manager = User::create(['name' => 'Менеджер Кнопки', 'phone' => '79000000077', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->manager->forceFill(['telegram_chat_id' => 555, 'telegram_linked_at' => now()->subDay()])->save();
    }

    /** Адрес, который Telegram откроет по кнопке `login_url`: наш адрес плюс подписанные поля. */
    private function signed(string $to, int $id = 555): string
    {
        $data = ['id' => $id, 'first_name' => 'Иван', 'auth_date' => time()];
        ksort($data);
        $check = implode("\n", array_map(fn ($k, $v) => $k.'='.$v, array_keys($data), $data));
        $data['hash'] = hash_hmac('sha256', $check, hash('sha256', 'test-token', true));

        return '/telegram/go?'.http_build_query(['to' => $to] + $data);
    }

    public function test_button_is_login_url_only_for_site(): void
    {
        $site = TelegramChannel::button('Посмотреть', Surface::Site->url('/offers?view=fresh'));
        $this->assertStringContainsString('/telegram/go?to='.rawurlencode('/offers?view=fresh'), $site['login_url']['url']);
        $crm = TelegramChannel::button('Открыть', Surface::Crm->url('/work/chats/1'));
        $this->assertArrayHasKey('url', $crm);
    }

    /** Путь CRM — на хост CRM (06.11.2026: «Открыть сделку» из «Менеджер берёт сделку» вела на xcar.ru/work/deals/…). */
    public function test_crm_path_button_goes_to_crm(): void
    {
        $notice = fn (string $href) => new class($href) extends \App\Notifications\Notice
        {
            public function __construct(private string $to) {}

            public function title(): string { return 't'; }

            public function label(): string { return 'l'; }

            public function href(): string { return $this->to; }
        };
        $this->assertSame(Surface::Crm->url('/work/deals/57'), $notice('/work/deals/57')->telegramUrl());
        $this->assertSame(Surface::Site->url('/deals/57'), $notice('/deals/57')->telegramUrl());
    }

    public function test_signature_logs_in_once_and_keeps_the_app_device(): void
    {
        $app = Device::create(['user_id' => $this->manager->id, 'token_hash' => hash('sha256', 'tok'), 'public_key' => [], 'label' => 'Safari, iPhone',
            'topic' => 'lock/test', 'verified_until' => now()]);
        $url = $this->signed('/offers/123');

        $this->get($url)->assertRedirect('/offers/123');
        $this->assertAuthenticatedAs($this->manager);
        $this->assertNull($app->fresh()->revoked_at);
        // Страница в браузере Telegram устройства не заводит — приложение остаётся.
        $this->get('/offers', ['Accept' => 'text/html'])->assertOk();
        $this->assertNull($app->fresh()->revoked_at);

        // Та же подпись второй раз не входит.
        auth()->logout();
        $this->flushSession();
        $this->get($url)->assertRedirect('/offers/123');
        $this->assertGuest();
    }

    public function test_foreign_path_and_bad_signature(): void
    {
        // Чужой адрес — не уводит наружу, только на свой корень.
        $this->get($this->signed('//evil.example/x'))->assertRedirect('/');
        auth()->logout();
        $this->flushSession();
        // Подпись не сошлась — не входит.
        $this->get('/telegram/go?to=/offers&id=555&auth_date='.time().'&hash=bad')->assertRedirect('/offers');
        $this->assertGuest();
    }

    public function test_telegram_session_expires_after_twelve_hours(): void
    {
        $this->get($this->signed('/offers'));
        $this->assertAuthenticatedAs($this->manager);
        $this->travel(TelegramEntry::HOURS * 60 + 1)->minutes();
        $this->get('/offers', ['Accept' => 'text/html'])->assertRedirect('/login');
        $this->assertGuest();
    }
}
