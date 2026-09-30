<?php

namespace App\Telegram\Console;

use App\Telegram\Bot;
use App\Telegram\UpdateHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Длинный опрос Telegram: вебхук до нас не доходит, а IPv6 наружу есть. Эстафета без пауз: планировщик
 * запускает опрос каждую минуту, новый ждёт замок, пока прежний дорабатывает, и подхватывает сразу.
 * Раньше опрос жил 290 с и выходил, а следующий стартовал только на ближайшей минуте — бот молчал до минуты
 * каждые пять минут. Срок `ttl` — от запуска, последний запрос укорачивается под него: процессы не копятся.
 */
final class Poll extends Command
{
    private const OFFSET = 'telegram:offset';

    private const LOCK = 'telegram:poll';

    protected $signature = 'telegram:poll {--ttl=62} {--timeout=25}';

    protected $description = 'Забирает обновления Telegram длинным опросом';

    public function handle(Bot $bot, UpdateHandler $handler): int
    {
        if (! $bot->configured()) {
            $this->warn('Токен бота не задан');

            return self::SUCCESS;
        }

        $deadline = time() + max(1, (int) $this->option('ttl'));
        // Ждём, пока прежний опрос дорабатывает свой последний запрос; не дождались до своего срока — уходим.
        $key = crc32(self::LOCK);
        while (! DB::selectOne('select pg_try_advisory_lock(?) as ok', [$key])->ok) {
            if (time() >= $deadline - 5) {
                return self::SUCCESS;
            }
            usleep(250_000);
        }
        try {
            if (! Cache::has('telegram:webhook-dropped')) {
                try {
                    $bot->dropWebhook();
                    Cache::put('telegram:webhook-dropped', true, 3600);
                } catch (Throwable $e) {
                    $this->warn('Вебхук не снялся: '.$e->getMessage());
                }
            }
            $offset = (int) Cache::get(self::OFFSET, 0);
            while (($left = $deadline - time()) > 0) {
                $started = microtime(true);
                try {
                    $updates = $bot->updates($offset, max(1, min((int) $this->option('timeout'), $left)));
                } catch (Throwable $e) {
                    Log::warning('Telegram: опрос сорвался', ['error' => $e->getMessage()]);
                    sleep(5);

                    continue;
                }
                // Мгновенный пустой ответ — не крутиться вхолостую.
                if ($updates === [] && microtime(true) - $started < 1.0) {
                    sleep(1);
                }
                foreach ($updates as $update) {
                    $offset = max($offset, (int) ($update['update_id'] ?? 0) + 1);
                    $handler->handle($update);
                    // Смещение — по каждому разобранному: убитый посреди пачки процесс не повторит сделанное.
                    Cache::forever(self::OFFSET, $offset);
                }
            }

            return self::SUCCESS;
        } finally {
            DB::select('select pg_advisory_unlock(?)', [$key]);
        }
    }
}
