<?php

namespace App\Support\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Работник стартует — взятые до него задачи его очередей вернуть сразу (26.11.2026, владелец: «после выкладки сразу же как можно
 * скорее продолжать очередь»). Выкладка и перезапуск контейнера убивают работника посреди задачи (SIGKILL после
 * `stop_grace_period`): строка остаётся взятой, и Laravel вернул бы её только через `retry_after` — чтение документов через
 * 16 мин, файлы, почту и Мигторг через 35, а задачу с одной попыткой — сразу в упавшие. У каждой очереди работник один
 * (`deploy/compose.yaml`), и до своего старта он ничего не берёт: всё взятое в его очередях — сироты прежнего. Убийство — не
 * попытка: `attempts` назад. Зовёт команда работника перед `queue:work`.
 */
final class ReleaseOrphans extends Command
{
    protected $signature = 'queue:release-orphans {queues : очереди работника через запятую}';

    protected $description = 'Вернуть в очередь задачи, взятые убитым работником этих очередей';

    public function handle(): int
    {
        $queues = array_values(array_filter(array_map('trim', explode(',', (string) $this->argument('queues')))));
        $n = DB::table(config('queue.connections.database.table', 'jobs'))->whereIn('queue', $queues)->whereNotNull('reserved_at')
            ->update(['reserved_at' => null, 'attempts' => DB::raw('greatest(attempts - 1, 0)')]);
        if ($n) {
            $this->info("Возвращено в очередь: {$n}");
        }

        return self::SUCCESS;
    }
}
