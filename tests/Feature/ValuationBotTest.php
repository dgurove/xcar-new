<?php

namespace Tests\Feature;

use App\Offers\Actions\MatchValuations;
use App\Offers\Offer;
use App\Telegram\Bot;
use App\Telegram\Jobs\ValuationFromChat;
use App\Telegram\UpdateHandler;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Скрин СМС Марины в бота (28.10.2026, владелец: «просто телеграм-боту скидывать, чтобы он сам проставлял; только для
 * админов»): текст «номер сумма» (фото читает тот же путь через OCR) → ответ с разбором и «Проставить N» → закупочная.
 */
class ValuationBotTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sends_numbers_and_floors_are_set_by_button(): void
    {
        config(['xcar.telegram.token' => 'test-token', 'xcar.mercure.hub' => '']);
        $sent = [];
        $n = 10;
        Http::fake(['api.telegram.org/*' => function ($r) use (&$sent, &$n) {
            $sent[] = urldecode($r->body());

            return Http::response(['ok' => true, 'result' => ['message_id' => ++$n]]);
        }]);
        $admin = User::create(['name' => 'Дмитрий', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $admin->forceFill(['telegram_chat_id' => 777, 'telegram_linked_at' => now()])->save();
        $manager = User::create(['name' => 'Иван', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $manager->forceFill(['telegram_chat_id' => 888, 'telegram_linked_at' => now()])->save();
        $vendor = Vendor::create(['name' => 'Т-Страхование тест']);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => 'AUT-26-865317']);
        $message = fn (int $from, string $text) => app(UpdateHandler::class)->handle(['update_id' => random_int(1, 99999), 'message' => [
            'message_id' => 1, 'chat' => ['id' => $from, 'type' => 'private'], 'from' => ['id' => $from], 'text' => $text]]);

        Bus::fake([ValuationFromChat::class]);
        $message(888, 'AUT-26-865317 367000');
        Bus::assertNotDispatched(ValuationFromChat::class);
        $message(777, "AUT-26-865317 367000\nAUT-26-794040 1201000");
        Bus::assertDispatched(ValuationFromChat::class, fn ($job) => $job->userId === $admin->id && str_contains((string) $job->text, '865317'));

        // Разбор: найденный — «Проставить 1», ненайденный — «Завести 1».
        (new ValuationFromChat(777, $admin->id, null, "AUT-26-865317 367000\nAUT-26-794040 1201000"))->handle(app(Bot::class), app(MatchValuations::class));
        $last = end($sent);
        $this->assertStringContainsString('Проставить 1', $last);
        $this->assertStringContainsString('Завести 1', $last);
        preg_match('/valuation:(\d+):apply/', $last, $m);

        $press = fn (int $from, string $action) => app(UpdateHandler::class)->handle(['update_id' => random_int(1, 99999), 'callback_query' => [
            'id' => 'q', 'from' => ['id' => $from], 'data' => "valuation:{$m[1]}:{$action}", 'message' => ['message_id' => 5, 'chat' => ['id' => $from]]]]);
        $press(888, 'apply');
        $this->assertNull($offer->fresh()->floor_price);
        $press(777, 'apply');
        $this->assertSame(367000, (int) $offer->fresh()->floor_price);
    }
}
