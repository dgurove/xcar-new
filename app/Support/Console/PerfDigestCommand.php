<?php

namespace App\Support\Console;

use App\Telegram\Jobs\NotifyOwner;
use App\Telegram\Messages\SlowDigest;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Сводка медленных ответов за день владельцу (`ServerTiming` → `storage/logs/slow-*.log`, 10.11.2026): сколько, худшие
 * места адресом без номеров, 5xx. Пусто — молчит. `--day=2026-11-10 --print` — посмотреть в консоли.
 */
final class PerfDigestCommand extends Command
{
    protected $signature = 'perf:digest {--day= : день YYYY-MM-DD, по умолчанию сегодня} {--print : только вывести}';

    protected $description = 'Медленные ответы за день — сводка владельцу в Telegram';

    public function handle(): int
    {
        $day = $this->option('day') ? Carbon::parse($this->option('day')) : now();
        $rows = self::rows($day);
        if (! $rows) {
            $this->info('Медленных нет');

            return self::SUCCESS;
        }
        $lines = self::summary($rows);
        if ($this->option('print')) {
            $this->line(implode("\n", $lines));
        } else {
            NotifyOwner::dispatch(new SlowDigest($day->format('d.m'), $lines));
        }

        return self::SUCCESS;
    }

    /** @return list<array> строки журнала за день */
    public static function rows(Carbon $day): array
    {
        $file = storage_path('logs/slow-'.$day->format('Y-m-d').'.log');
        if (! is_file($file)) {
            return [];
        }
        $rows = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (preg_match('/\{.*\}/', $line, $m) && is_array($row = json_decode($m[0], true)) && isset($row['ms'])) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return list<string> */
    public static function summary(array $rows): array
    {
        $errors = count(array_filter($rows, fn ($r) => ($r['status'] ?? 0) >= 500));
        $slow = array_filter($rows, fn ($r) => ($r['status'] ?? 0) < 500);
        $people = count(array_unique(array_filter(array_column($rows, 'user'))));
        $lines = ['Медленных ответов: '.count($slow).($errors ? ', ошибок 500: '.$errors : '').', людей: '.$people];
        $places = [];
        foreach ($slow as $r) {
            $key = ($r['host'] ?? '').' '.preg_replace('/\d+/', 'N', strtok((string) $r['path'], '?') ?: '/');
            $places[$key][] = (int) $r['ms'];
        }
        uasort($places, fn ($a, $b) => count($b) <=> count($a) ?: max($b) <=> max($a));
        foreach (array_slice($places, 0, 6, true) as $place => $ms) {
            sort($ms);
            $lines[] = $place.' — '.count($ms).' раз, медиана '.round($ms[intdiv(count($ms), 2)] / 1000, 1).' с, худший '.round(max($ms) / 1000, 1).' с';
        }

        return $lines;
    }
}
