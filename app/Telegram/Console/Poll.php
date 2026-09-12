<?php

namespace App\Telegram\Console;

use App\Support\HoldsSingleRun;
use App\Telegram\Bot;
use App\Telegram\UpdateHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Длинный опрос Telegram: вебхук до нас не доходит, а IPv6 наружу есть. Живёт до `ttl`, планировщик ставит следующий. */
final class Poll extends Command
{
    use HoldsSingleRun;

    private const OFFSET = 'telegram:offset';

    protected $signature = 'telegram:poll {--ttl=290} {--timeout=25}';

    protected $description = 'Забирает обновления Telegram длинным опросом';

    public function handle(Bot $bot, UpdateHandler $handler): int
    {
        if (! $bot->configured()) {
            $this->warn('Токен бота не задан');

            return self::SUCCESS;
        }

        return $this->holdingSingleRun('telegram:poll', function () use ($bot, $handler) {
            try {
                $bot->dropWebhook();
            } catch (Throwable $e) {
                $this->warn('Вебхук не снялся: '.$e->getMessage());
            }
            $timeout = max(1, (int) $this->option('timeout'));
            $deadline = time() + max(1, (int) $this->option('ttl'));
            $offset = (int) Cache::get(self::OFFSET, 0);
            while (time() < $deadline) {
                $started = microtime(true);
                try {
                    $updates = $bot->updates($offset, $timeout);
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
        });
    }
}
