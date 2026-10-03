<?php

namespace App\Telegram\Offers\Console;

use App\Telegram\Offers\Announce;
use App\Telegram\Offers\Handler;
use App\Telegram\Offers\OffersBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Once;
use Throwable;

/**
 * Бот предложений — постоянный сервис `offers-bot` (compose). Длинный опрос по IPv6 (вебхук до сервера не доходит) и
 * часы (`Announce::due`, раз в ~20 с). Каждая запись уходит дорожке своего чата — дочернему процессу
 * `offers-bot:lane` по `chat_id % lanes`: внутри чата порядок сохраняется, разные чаты идут параллельно — в 16:00,
 * когда все разом жмут «👍 1», медленная загрузка кадра одному не держит остальных. Одна дорожка — разбор прямо здесь.
 * Процесс живёт `ttl` и выходит — compose поднимет заново; второй экземпляр (выкладка) ждёт замок.
 */
final class Run extends Command
{
    private const OFFSET = 'offers-bot:offset';

    protected $signature = 'offers-bot:run {--ttl=21600} {--timeout=20}';

    protected $description = 'Бот предложений: опрос Telegram, часы и дорожки';

    /** @var array<int, array{proc: resource, in: resource}> */
    private array $lanes = [];

    public function handle(OffersBot $bot, Handler $handler): int
    {
        if (! $bot->configured()) {
            $this->warn('Токен бота предложений не задан — сплю');
            sleep(300);

            return self::SUCCESS;
        }
        $deadline = time() + max(60, (int) $this->option('ttl'));
        $key = crc32('offers-bot:run');
        while (! DB::selectOne('select pg_try_advisory_lock(?) as ok', [$key])->ok) {
            sleep(2);
        }
        try {
            try {
                $bot->dropWebhook();
            } catch (Throwable $e) {
                $this->warn('Вебхук не снялся: '.$e->getMessage());
            }
            $n = $bot->lanes();
            $this->line("Бот предложений: @{$bot->username()}, дорожек {$n}");
            $offset = (int) Cache::get(self::OFFSET, 0);
            $timers = 0;
            while (time() < $deadline) {
                if (time() >= $timers) {
                    $timers = time() + 20;
                    try {
                        foreach (Announce::due() as $item) {
                            $this->dispatch($item, $n, $handler);
                        }
                    } catch (Throwable $e) {
                        Log::warning('Бот предложений: часы сорвались', ['error' => $e->getMessage()]);
                    }
                }
                $started = microtime(true);
                try {
                    $updates = $bot->updates($offset, (int) $this->option('timeout'));
                } catch (Throwable $e) {
                    Log::warning('Бот предложений: опрос сорвался', ['error' => $e->getMessage()]);
                    sleep(5);

                    continue;
                }
                if ($updates === [] && microtime(true) - $started < 1.0) {
                    sleep(1);
                }
                foreach ($updates as $update) {
                    $offset = max($offset, (int) ($update['update_id'] ?? 0) + 1);
                    $this->dispatch(['update' => $update], $n, $handler);
                    Cache::forever(self::OFFSET, $offset);
                }
            }

            return self::SUCCESS;
        } finally {
            foreach ($this->lanes as $lane) {
                @fclose($lane['in']);
                proc_close($lane['proc']);
            }
            DB::select('select pg_advisory_unlock(?)', [$key]);
        }
    }

    private function dispatch(array $item, int $lanes, Handler $handler): void
    {
        if ($lanes === 1) {
            $handler->handle($item);
            Once::flush();

            return;
        }
        $i = abs(Handler::chatOf($item)) % $lanes;
        $lane = $this->lane($i);
        if (@fwrite($lane['in'], json_encode($item, JSON_UNESCAPED_UNICODE)."\n") === false) {
            // Дорожка умерла посреди записи — поднимаем и отдаём ещё раз.
            unset($this->lanes[$i]);
            @fwrite($this->lane($i)['in'], json_encode($item, JSON_UNESCAPED_UNICODE)."\n");
        }
    }

    /** @return array{proc: resource, in: resource} */
    private function lane(int $i): array
    {
        if (isset($this->lanes[$i]) && proc_get_status($this->lanes[$i]['proc'])['running']) {
            return $this->lanes[$i];
        }
        $proc = proc_open([PHP_BINARY, base_path('artisan'), 'offers-bot:lane', "--lane={$i}"], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
        if (! $proc) {
            throw new \RuntimeException("Дорожка {$i} не запустилась");
        }

        return $this->lanes[$i] = ['proc' => $proc, 'in' => $pipes[0]];
    }
}
