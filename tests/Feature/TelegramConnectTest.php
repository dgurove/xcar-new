<?php

namespace Tests\Feature;

use App\Telegram\ConnectLink;
use App\Telegram\UpdateHandler;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «Войти через XCar» из бота (21.10.2026, владелец: «скинул ссылку на бота — и менеджеры залогинились в свои аккаунты»):
 * незнакомому — два бота и кнопка; страница подключает после входа, ссылка — один раз и 30 минут; бот предложений —
 * только менеджеру; основной бот узнаёт подписчика бота предложений без второго входа.
 */
class TelegramConnectTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['db']->extend('pgsql_async', fn () => $this->app['db']->connection('pgsql'));
        config(['xcar.telegram.token' => 'test-token', 'xcar.telegram.username' => 'xcar_test_bot', 'xcar.telegram.owner_chat_id' => 1,
            'xcar.telegram.offers.token' => 'offers-token', 'xcar.telegram.offers.username' => 'xcar_offers_test_bot', 'xcar.mercure.hub' => '']);
        $id = 70;
        Http::fake(['api.telegram.org/*' => function () use (&$id) {
            return Http::response(['ok' => true, 'result' => ['message_id' => ++$id]]);
        }]);
        $this->manager = User::create(['name' => 'Иван Подключаев', 'phone' => '79000000055', 'roles' => [Role::Manager], 'approved_at' => now()]);
    }

    /** Тексты, что боты отправили в чат. */
    private function sent(int $chat): string
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (HttpRequest $r) => str_ends_with($r->url(), '/sendMessage') && (int) ($r['chat_id'] ?? 0) === $chat)
            ->map(fn (HttpRequest $r) => $r['text'].' '.json_encode($r['reply_markup'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->implode("\n");
    }

    private function start(int $chat, string $text = '/start'): void
    {
        app(UpdateHandler::class)->handle(['update_id' => random_int(1, 99999), 'message' => ['message_id' => 1, 'chat' => ['id' => $chat, 'type' => 'private'],
            'from' => ['id' => $chat, 'first_name' => 'Иван', 'username' => 'ivan'], 'text' => $text]]);
    }

    private function path(string $bot, int $tg = 777): string
    {
        $url = ConnectLink::url($bot, ['id' => $tg, 'first_name' => 'Иван', 'username' => 'ivan']);

        return parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
    }

    public function test_stranger_gets_two_bots_and_login_button(): void
    {
        $this->start(777, '/start hi');
        $sent = $this->sent(777);
        $this->assertStringContainsString('У XCar два бота', $sent);
        $this->assertStringContainsString('@xcar_offers_test_bot', $sent);
        $this->assertStringContainsString('Войти через XCar', $sent);
        $this->assertStringContainsString('connect?bot=main', $sent);
    }

    public function test_connect_after_login_links_once(): void
    {
        $path = $this->path(ConnectLink::MAIN);
        $this->get($path)->assertRedirect('/login');

        $this->actingAs($this->manager)->get($path)->assertOk()->assertSee('Иван @ivan')->assertSee('Иван Подключаев');
        $this->actingAs($this->manager)->post($path)->assertOk()->assertSee('Telegram подключён');
        $this->assertSame(777, (int) $this->manager->fresh()->telegram_chat_id);
        $this->assertStringContainsString('готово', $this->sent(777));
        // И предложит бот предложений — менеджер на него не подписан.
        $this->assertStringContainsString('Подключить @xcar_offers_test_bot', $this->sent(777));

        $this->actingAs($this->manager)->post($path)->assertStatus(410);
        $this->travel(ConnectLink::MINUTES + 1)->minutes();
        $this->actingAs($this->manager)->get($path)->assertStatus(410);
    }

    public function test_offers_bot_connects_manager_but_not_buyer(): void
    {
        $this->actingAs($this->manager)->post($this->path(ConnectLink::OFFERS))->assertOk();
        $this->assertSame(777, (int) DB::table('offer_bot_chats')->where('user_id', $this->manager->id)->value('chat_id'));
        $this->assertNull($this->manager->fresh()->telegram_chat_id);

        $buyer = User::create(['name' => 'Покупатель', 'phone' => '79000000056', 'roles' => [Role::Buyer], 'manager_id' => $this->manager->id, 'approved_at' => now()]);
        $this->actingAs($buyer)->post($this->path(ConnectLink::OFFERS, 778))->assertOk();
        $this->assertFalse(DB::table('offer_bot_chats')->where('user_id', $buyer->id)->exists());
    }

    public function test_main_bot_recognizes_offers_subscriber(): void
    {
        DB::table('offer_bot_chats')->insert(['user_id' => $this->manager->id, 'chat_id' => 888, 'created_at' => now(), 'updated_at' => now()]);
        $this->start(888);
        $this->assertSame(888, (int) $this->manager->fresh()->telegram_chat_id);
        $this->assertStringContainsString('готово', $this->sent(888));
    }
}
