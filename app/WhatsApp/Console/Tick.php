<?php

namespace App\WhatsApp\Console;

use App\WhatsApp\Group;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Обход группы WhatsApp раз в минуту (`routes/console.php`): шлёт, что пора (`Group::step`), а следующую — в ту же минуту,
 * если пауза в неё укладывается: паузы 30–90 с без запуска каждые 15 с.
 */
final class Tick extends Command
{
    protected $signature = 'whatsapp:tick';

    protected $description = 'WhatsApp: отправить в группу новые предложения';

    public function handle(Group $group): int
    {
        $end = time() + 50;
        try {
            while (time() + ($wait = $group->wait()) <= $end) {
                sleep($wait);
                if (! $group->step()) {
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
