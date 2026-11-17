<?php

namespace App\Support\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Сколько стоят страницы на самом деле (17.11.2026): по журналу `perf-*.log` (`ServerTiming` пишет каждый GET, телефон —
 * замеры нажатий `PerfController`) — медиана и p90 времени сервера, запросы и вес по разделу и виду запроса, а для
 * нажатий — время до отклика и до данных. Сравнивать «до» и «после» правок: `perf:pages --days=7 --host=crm`.
 */
final class PerfPagesCommand extends Command
{
    protected $signature = 'perf:pages {--days=1 : сколько дней назад от сегодня} {--day= : один день YYYY-MM-DD}
        {--host= : часть имени хоста, например crm} {--min=5 : строки реже не показывать}';

    protected $description = 'Медиана и p90 страниц и нажатий по журналу perf';

    public function handle(): int
    {
        $days = $this->option('day')
            ? [Carbon::parse($this->option('day'))]
            : array_map(fn (int $i) => now()->subDays($i), range(0, max(1, (int) $this->option('days')) - 1));
        $pages = $taps = [];
        foreach ($days as $day) {
            foreach (self::rows($day) as [$type, $row]) {
                if ($this->option('host') && ! str_contains((string) ($row['host'] ?? ''), (string) $this->option('host'))) {
                    continue;
                }
                $type === 'tap' ? $taps[self::tapKey($row)][] = $row : $pages[self::pageKey($row)][] = $row;
            }
        }
        $min = (int) $this->option('min');
        $this->table(['страница', 'вид', 'раз', 'мс p50', 'p90', 'база', 'запр.', 'рендер', 'КБ'], self::lines($pages, $min, fn (array $rows, string $key) => [
            ...explode('|', $key), count($rows), self::pct($rows, 'ms', 50), self::pct($rows, 'ms', 90), self::pct($rows, 'db', 50),
            self::pct($rows, 'q', 50), self::pct($rows, 'view', 50), self::pct($rows, 'kb', 50),
        ]));
        if ($taps) {
            $this->table(['нажатие', 'где', 'раз', 'отклик p50', 'p90', 'данные p50', 'p90'], self::lines($taps, $min, fn (array $rows, string $key) => [
                ...explode('|', $key), count($rows), self::pct($rows, 'shown', 50), self::pct($rows, 'shown', 90),
                self::pct($rows, 'ready', 50), self::pct($rows, 'ready', 90),
            ]));
        }

        return self::SUCCESS;
    }

    /** @return list<array{0: string, 1: array}> строки журнала за день: вид записи и её поля */
    public static function rows(Carbon $day): array
    {
        $file = storage_path('logs/perf-'.$day->format('Y-m-d').'.log');
        $rows = [];
        foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
            if (preg_match('/\b(page|tap) (\{.*\})/', $line, $m) && is_array($row = json_decode($m[2], true))) {
                $rows[] = [$m[1], $row];
            }
        }

        return $rows;
    }

    /** Страница без номеров; у списков с вкладками — вкладка, она и есть экран. */
    private static function pageKey(array $row): string
    {
        $path = (string) ($row['path'] ?? '/');
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $name = preg_replace('/\d+/', 'N', strtok($path, '?') ?: '/');
        $tab = $query['preset'] ?? $query['box'] ?? $query['tab'] ?? null;

        return strtok((string) ($row['host'] ?? ''), '.').' '.$name.($tab ? ' ['.$tab.']' : '').'|'.($row['kind'] ?? 'page');
    }

    private static function tapKey(array $row): string
    {
        return ($row['what'] ?? 'visit').' '.($row['to'] ?? '').'|'.(($row['app'] ?? false) ? 'приложение' : (($row['phone'] ?? false) ? 'телефон' : 'ПК'));
    }

    /** @return list<list<mixed>> строки таблицы: частые первыми */
    private static function lines(array $groups, int $min, callable $cells): array
    {
        $groups = array_filter($groups, fn (array $rows) => count($rows) >= $min);
        uasort($groups, fn (array $a, array $b) => count($b) <=> count($a));

        return array_map($cells, array_values($groups), array_keys($groups));
    }

    private static function pct(array $rows, string $field, int $p): string
    {
        $values = array_values(array_filter(array_column($rows, $field), fn ($v) => $v !== null));
        if (! $values) {
            return '';
        }
        sort($values);

        return (string) $values[min(count($values) - 1, (int) floor(count($values) * $p / 100))];
    }
}
