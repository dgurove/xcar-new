<?php

namespace App\WhatsApp\Console;

use App\WhatsApp\Gateway;
use App\WhatsApp\Inbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Входящие WhatsApp (коды привязки) — длинным опросом службы в Цюрихе, эстафетой, как `telegram:poll`: планировщик
 * запускает каждую минуту, новый ждёт замок, пока прежний дорабатывает, и подхватывает сразу. Прод к Цюриху ходит сам —
 * входа снаружи нет.
 */
final class Poll extends Command
{
    private const AFTER = 'whatsapp:inbox-after';

    private const LOCK = 'whatsapp:poll';

    protected $signature = 'whatsapp:poll {--ttl=62} {--timeout=25}';

    protected $description = 'WhatsApp: забрать коды привязки длинным опросом';

    public function handle(Gateway $gateway, Inbox $inbox): int
    {
        if (! $gateway->configured()) {
            return self::SUCCESS;
        }
        $deadline = time() + max(1, (int) $this->option('ttl'));
        $key = crc32(self::LOCK);
        while (! DB::selectOne('select pg_try_advisory_lock(?) as ok', [$key])->ok) {
            if (time() >= $deadline - 5) {
                return self::SUCCESS;
            }
            usleep(250_000);
        }
        try {
            $after = (int) Cache::get(self::AFTER, 0);
            while (($left = $deadline - time()) > 0) {
                $started = microtime(true);
                try {
                    $rows = $gateway->inbox($after, max(1, min((int) $this->option('timeout'), $left)));
                } catch (Throwable $e) {
                    // Лежит туннель или служба — тревогу поднимает `whatsapp:status`; в журнал — раз в час.
                    if (Cache::add('whatsapp:poll-failed', true, 3600)) {
                        Log::warning('WhatsApp: опрос сорвался', ['error' => $e->getMessage()]);
                    }
                    sleep(5);

                    continue;
                }
                if ($rows === [] && microtime(true) - $started < 1.0) {
                    sleep(1);
                }
                // Базу службы завели заново — она сама отдаёт с начала (наш `after` больше её последнего номера).
                foreach ($rows as $row) {
                    $after = (int) $row['id'];
                    $inbox->handle($row);
                    Cache::forever(self::AFTER, $after);
                }
            }

            return self::SUCCESS;
        } finally {
            DB::select('select pg_advisory_unlock(?)', [$key]);
        }
    }
}
