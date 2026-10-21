<?php

namespace Tests\Feature;

use App\Telegram\ConnectLink;
use App\Telegram\StartLink;
use App\Telegram\UpdateHandler;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «Войти через XCar» из бота (21.10.2026): незнакомому — два бота и кнопка; страница после входа даёт ссылку привязки
 * своего аккаунта обратно в бот — привязывается тот, кто её открыл, а не id из ссылки (её можно переслать); покупателю —
 * в основной бот; основной бот узнаёт подписчика бота предложений, но не перепривязывает уже привязанного. Ошибка здесь
 * молчалива и дорога — чужой Telegram получал бы сделки и входил бы в аккаунт, — поэтому тест.
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

    public function test_stranger_gets_two_bots_and_login_button(): void
    {
        $this->start(777, '/start hi');
        $sent = $this->sent(777);
        $this->assertStringContainsString('У XCar два бота', $sent);
        $this->assertStringContainsString('@xcar_offers_test_bot', $sent);
        $this->assertStringContainsString('connect?bot=main', $sent);
    }

    public function test_page_gives_own_link_and_bot_links_whoever_opens_it(): void
    {
        $this->get('/telegram/connect?bot=main')->assertRedirect('/login');

        $page = $this->actingAs($this->manager)->get('/telegram/connect?bot=main')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~t\.me/xcar_test_bot\?start=(L[\w-]+)~', $page);
        preg_match('~start=(L[\w-]+)~', $page, $m);
        $this->assertSame(['link', $this->manager->id, false], StartLink::parse($m[1]));
        // Ничего не привязано, пока ссылку не открыли в Telegram.
        $this->assertNull($this->manager->fresh()->telegram_chat_id);

        $this->start(777, '/start '.$m[1]);
        $this->assertSame(777, (int) $this->manager->fresh()->telegram_chat_id);
        $this->assertStringContainsString('готово', $this->sent(777));
        $this->assertStringContainsString('Подключить @xcar_offers_test_bot', $this->sent(777));
    }

    public function test_buyer_from_offers_bot_is_sent_to_main_bot(): void
    {
        $buyer = User::create(['name' => 'Покупатель', 'phone' => '79000000056', 'roles' => [Role::Buyer], 'manager_id' => $this->manager->id, 'approved_at' => now()]);
        $this->actingAs($buyer)->get('/telegram/connect?bot=offers')->assertOk()->assertSee('t.me/xcar_test_bot?start=L', false);
        $this->actingAs($this->manager)->get('/telegram/connect?bot=offers')->assertOk()->assertSee('t.me/xcar_offers_test_bot?start=L', false);
    }

    public function test_main_bot_recognizes_offers_subscriber_only_if_unlinked(): void
    {
        DB::table('offer_bot_chats')->insert(['user_id' => $this->manager->id, 'chat_id' => 888, 'created_at' => now(), 'updated_at' => now()]);
        $this->start(888);
        $this->assertSame(888, (int) $this->manager->fresh()->telegram_chat_id);

        // Уже привязан к другому Telegram — не перепривязываем молча.
        $other = User::create(['name' => 'Второй', 'phone' => '79000000057', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $other->forceFill(['telegram_chat_id' => 5005])->save();
        DB::table('offer_bot_chats')->insert(['user_id' => $other->id, 'chat_id' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->start(999);
        $this->assertSame(5005, (int) $other->fresh()->telegram_chat_id);
        $this->assertStringContainsString('Войти через XCar', $this->sent(999));
    }

    public function test_one_account_per_telegram_and_logout_from_both(): void
    {
        $other = User::create(['name' => 'Тестовый Менеджер', 'phone' => '79000000058', 'roles' => [Role::Manager], 'approved_at' => now()]);
        // Бот предложений у этого Telegram — под чужим аккаунтом; основной привязали к своему — чужая подписка ушла.
        DB::table('offer_bot_chats')->insert(['user_id' => $other->id, 'chat_id' => 777, 'created_at' => now(), 'updated_at' => now()]);
        $this->start(777, '/start '.StartLink::link($this->manager));
        $this->assertSame(777, (int) $this->manager->fresh()->telegram_chat_id);
        $this->assertFalse(DB::table('offer_bot_chats')->where('chat_id', 777)->exists());

        DB::table('offer_bot_chats')->insert(['user_id' => $this->manager->id, 'chat_id' => 777, 'created_at' => now(), 'updated_at' => now()]);
        $this->start(777, '/logout');
        $this->assertNull($this->manager->fresh()->telegram_chat_id);
        $this->assertFalse(DB::table('offer_bot_chats')->where('chat_id', 777)->exists());
        $this->assertStringContainsString('Вы вышли из аккаунта Иван Подключаев', $this->sent(777));
    }
}
