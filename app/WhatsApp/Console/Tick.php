<?php

namespace App\WhatsApp\Console;

use App\WhatsApp\Sender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Обход WhatsApp раз в минуту (`routes/console.php`): шлёт, что пора (`Sender::step` — группа, личные, оценка), а следующее —
 * в ту же минуту, если пауза в неё укладывается: паузы 30–90 с без запуска каждые 15 с.
 */
final class Tick extends Command
{
    protected $signature = 'whatsapp:tick';

    protected $description = 'WhatsApp: отправить новые предложения и просьбы оценить';

    public function handle(Sender $sender): int
    {
        $end = time() + 50;
        try {
            while (time() + ($wait = $sender->wait()) <= $end) {
                sleep($wait);
                if (! $sender->step()) {
                    break;
                }
            }
        } catch (Throwable $e) {
            // Лежит туннель или служба — тревогу поднимает `whatsapp:status`; в журнал — раз в час, а не каждую минуту.
            if (Cache::add('whatsapp:tick-failed', true, 3600)) {
                Log::warning('WhatsApp: обход сорвался', ['error' => $e->getMessage()]);
            }
        }

        return self::SUCCESS;
    }
}
