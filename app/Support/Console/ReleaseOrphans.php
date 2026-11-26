<?php

namespace App\Support\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
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
    /** Сколько раз одной задаче убийство не засчитывается в попытки. */
    private const FORGIVE = 2;

    protected $signature = 'queue:release-orphans {queues : очереди работника через запятую}';

    protected $description = 'Вернуть в очередь задачи, взятые убитым работником этих очередей';

    public function handle(): int
    {
        $queues = array_values(array_filter(array_map('trim', explode(',', (string) $this->argument('queues')))));
        $table = DB::table(config('queue.connections.database.table', 'jobs'));
        $n = 0;
        foreach ((clone $table)->whereIn('queue', $queues)->whereNotNull('reserved_at')->get(['id', 'attempts']) as $job) {
            // Убийство — не попытка, но не больше `FORGIVE` раз на задачу: та, что сама роняет работника (фатальная нехватка
            // памяти), иначе крутилась бы вечно и держала очередь — дальше её попытки считаются, и `tries` её снимает.
            $times = (int) Cache::get('queue:orphan:'.$job->id) + 1;
            Cache::put('queue:orphan:'.$job->id, $times, now()->addDay());
            $forgive = $times <= self::FORGIVE;
            $n += (clone $table)->where('id', $job->id)->whereNotNull('reserved_at')
                ->update(['reserved_at' => null] + ($forgive ? ['attempts' => max((int) $job->attempts - 1, 0)] : []));
        }
        if ($n) {
            $this->info("Возвращено в очередь: {$n}");
        }

        return self::SUCCESS;
    }
}
