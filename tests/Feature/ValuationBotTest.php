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
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Скрин СМС Марины в бота (28.10.2026, владелец: «просто телеграм-боту скидывать, чтобы он сам проставлял; только для
 * админов»): текст «номер сумма» (фото читает тот же путь через OCR, таблицу — `ValuationSheet`) → ответ с разбором и
 * «Проставить N» → закупочная.
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
        // Второе нажатие той же кнопки (двойной тап) ничего не пишет ещё раз.
        $press(777, 'apply');
        $this->assertStringContainsString('Ничего не изменилось', end($sent));
    }

    /**
     * Таблица Excel файлом (03.11.2026: Альфа шлёт оценки xlsx, бот её молча пропускал): читается как в окне, подпись к
     * файлу — вместе с ней.
     */
    public function test_admin_sends_spreadsheet_with_caption(): void
    {
        config(['xcar.telegram.token' => 'test-token', 'xcar.mercure.hub' => '']);
        Storage::fake('private');
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['НОМЕР УБЫТКА', 'СТАВКА'], ['5691/046/00885/26', 557500]]);
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        (new Xlsx($book))->save($path);
        $sent = [];
        Http::fake(['api.telegram.org/file/*' => Http::response((string) file_get_contents($path)),
            'api.telegram.org/*' => function ($r) use (&$sent) {
                if (str_contains($r->url(), 'getFile')) {
                    return Http::response(['ok' => true, 'result' => ['file_path' => 'documents/file_1.xlsx']]);
                }
                $sent[] = urldecode($r->body());

                return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
            }]);
        @unlink($path);
        $admin = User::create(['name' => 'Дмитрий', 'phone' => '79000000011', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $admin->forceFill(['telegram_chat_id' => 777, 'telegram_linked_at' => now()])->save();
        $vendor = Vendor::create(['name' => 'Т-Страхование тест']);
        $first = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => '5691/046/00885/26']);
        Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => 'AUT-26-865317']);

        Bus::fake([ValuationFromChat::class]);
        app(UpdateHandler::class)->handle(['update_id' => 1, 'message' => ['message_id' => 1, 'chat' => ['id' => 777, 'type' => 'private'], 'from' => ['id' => 777],
            'document' => ['file_id' => 'F1', 'file_unique_id' => 'U1', 'file_name' => '23.10-31.10.xlsx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'caption' => 'AUT-26-865317 367000']]);
        Bus::assertDispatched(ValuationFromChat::class, fn ($job) => ($job->file['kind'] ?? null) === ValuationFromChat::SHEET);

        (new ValuationFromChat(777, $admin->id, ['kind' => ValuationFromChat::SHEET, 'file_id' => 'F1', 'file_unique_id' => 'U1'], 'AUT-26-865317 367000'))
            ->handle(app(Bot::class), app(MatchValuations::class));
        $last = end($sent);
        $this->assertStringContainsString('Оценка из таблицы', $last);
        // Из таблицы и из подписи — обе машины.
        $this->assertStringContainsString('Проставить 2', $last);
        $this->assertNull($first->fresh()->floor_price);
    }
}
