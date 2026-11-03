<?php

namespace Tests\Feature;

use App\Telegram\Bot;
use App\Telegram\Jobs\NotifyOwner;
use App\Telegram\Messages\Message;
use App\Telegram\Messages\ServerError;
use App\Users\Role;
use App\Users\User;
use App\WhatsApp\Gateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Связь с Telegram и WhatsApp (03.11.2026, владелец: «туннель регулярно падает, должны быть фоллбеки»; «все ошибки только мне»):
 * не соединилось по одному пути — тот же запрос по следующему (`Support\Routes`); технические сбои — только в чат владельца.
 */
class TelegramRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_falls_over_to_the_next_route(): void
    {
        Cache::flush();
        config(['xcar.telegram.token' => 'test-token', 'xcar.telegram.via' => 'zurich:9443,zurich:9445,direct']);
        $used = [];
        Http::fake(function (Request $request, array $options) use (&$used) {
            $via = $options['curl'][CURLOPT_CONNECT_TO][0] ?? 'direct';
            $used[] = $via;

            return str_contains($via, ':9443') ? Http::failedConnection() : Http::response(['ok' => true, 'result' => ['message_id' => 7]]);
        });

        app(Bot::class)->send(111, 'Проверка');
        $this->assertSame('api.telegram.org:443:zurich:9445', end($used));
        $this->assertContains('api.telegram.org:443:zurich:9443', $used);

        // Лёгший путь две минуты идёт последним: следующее сообщение — сразу запасным.
        $used = [];
        app(Bot::class)->send(111, 'Ещё');
        $this->assertSame(['api.telegram.org:443:zurich:9445'], $used);
    }

    public function test_whatsapp_falls_over_to_the_next_route(): void
    {
        Cache::flush();
        config(['xcar.whatsapp.via' => 'http://zurich:9444,http://zurich:9446', 'xcar.whatsapp.token' => 'x']);
        Http::fake(fn (Request $request) => str_contains($request->url(), ':9444')
            ? Http::failedConnection()
            : Http::response(['connected' => true, 'logged_in' => true, 'stopped' => null, 'in_group' => true, 'group' => 'XCAR']));

        $this->assertTrue(app(Gateway::class)->status()['connected']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), ':9446/status'));
    }

    public function test_errors_go_only_to_the_owner(): void
    {
        config(['xcar.telegram.token' => 'test-token', 'xcar.telegram.owner_chat_id' => '111', 'xcar.telegram.via' => '']);
        $admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $admin->forceFill(['telegram_chat_id' => 222, 'telegram_linked_at' => now()])->save();
        $chats = [];
        Http::fake(function (Request $request) use (&$chats) {
            $chats[] = (int) $request['chat_id'];

            return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
        });

        $this->assertTrue((new \ReflectionClass(ServerError::class))->newInstanceWithoutConstructor()->ownerOnly());
        $error = new class extends Message
        {
            public function ownerOnly(): bool
            {
                return true;
            }

            protected function title(): string
            {
                return 'Ошибка 500';
            }

            protected function lines(): array
            {
                return ['RuntimeException'];
            }

            protected function decisions(): array
            {
                return [];
            }

            protected function link(): array
            {
                return ['text' => 'Открыть', 'url' => 'https://xcar.ru'];
            }
        };
        (new NotifyOwner($error))->handle(app(Bot::class));
        $this->assertSame([111], $chats);
    }
}
