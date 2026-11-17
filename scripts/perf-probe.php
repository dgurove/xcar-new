<?php

/**
 * Цена одного адреса на сервере (17.11.2026, «почему сервер так долго отдаёт»): число запросов к базе, время, вес ответа —
 * на холодном кэше (как после простоя или сброса счётчиков) и на тёплом, с самыми дорогими запросами и местами в коде.
 * Запрос идёт через настоящее ядро HTTP в этом же процессе, сеть и сервер не нужны.
 *
 *   php scripts/perf-probe.php '/offers?preset=all' /work/mail            # админ 3 на CRM
 *   php scripts/perf-probe.php '/offers?preset=all&peek=4058' --frame=detail --trace
 *   php scripts/perf-probe.php '/offers?preset=unpriced' --rows=4058      # ответ живому обновлению строк
 *   php scripts/perf-probe.php '/offers?preset=all&part=2' --list         # следующая сотня строк
 *   php scripts/perf-probe.php /deals --host=site --user=6
 *
 * Ключи: --user=3, --host=crm|site|park|logistics|partner, --frame=имя, --rows=ключи, --list, --top=6 (сколько строк
 * разбора), --trace (места в коде вместо текста запросов), --dups (одинаковые запросы с теми же значениями), --cold (разбор холодного прогона, а не тёплого).
 *
 * Мерить только так, а не `tinker`-ом: в консоли пакетные выборки на запрос (`once` в `RepriceAsk::dealDue`,
 * `ReplyReview::waiting`, `Position::firstCredits`) намеренно отключены, и список сделок даёт по четыре запроса на строку —
 * которых на сайте нет. Здесь приложение считает, что оно не в консоли. Кэш — в памяти процесса: общий кэш в базе не
 * трогается, чужие сессии с локальным сервером не страдают.
 */
putenv('APP_RUNNING_IN_CONSOLE=false');
$_ENV['APP_RUNNING_IN_CONSOLE'] = $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'false';

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config(['cache.default' => 'array']);
$app->forgetInstance('cache.store');

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;

$urls = [];
$opt = ['user' => 3, 'host' => 'crm', 'top' => 6];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $arg, $m)) {
        $opt[$m[1]] = $m[2] ?? true;
    } else {
        $urls[] = $arg;
    }
}
if (! $urls) {
    fwrite(STDERR, "Адрес не указан. Пример: php scripts/perf-probe.php '/offers?preset=all' --trace\n");
    exit(1);
}
$site = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'xcar.localhost';
$host = match ($opt['host']) {
    'site' => $site,
    default => config('xcar.'.$opt['host'].'_host') ?: $opt['host'].'.'.$site,
};
$headers = array_filter([
    'Turbo-Frame' => $opt['frame'] ?? null,
    'X-Live-Rows' => $opt['rows'] ?? null,
    'X-List' => isset($opt['list']) ? '1' : null,
]);

$log = [];
$on = false;
$GLOBALS['depth'] = isset($opt['grep']) ? 7 : 3;
DB::listen(function ($q) use (&$log, &$on) {
    if (! $on) {
        return;
    }
    $frames = [];
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 70) as $f) {
        $file = $f['file'] ?? '';
        if ($file === '' || str_contains($file, '/vendor/') || str_contains($file, '/scripts/') || str_contains($file, '/Middleware/')) {
            continue;
        }
        $file = str_replace(dirname(__DIR__).'/', '', $file);
        if (str_contains($file, '/framework/views/') || str_contains($file, '/cache/views/')) {
            // Скомпилированный вид — имя исходного шаблона из метки в конце файла.
            static $views = [];
            $views[$f['file']] ??= preg_match('#/\*\*PATH (\S+) ENDPATH\*\*/#', (string) @file_get_contents($f['file']), $vm)
                ? str_replace([dirname(__DIR__).'/resources/views/', '.blade.php'], ['', ''], $vm[1]) : null;
            if (! $views[$f['file']]) {
                continue;
            }
            $file = 'view:'.$views[$f['file']];
        }
        $frames[] = preg_replace('#^app/#', '', $file).':'.($f['line'] ?? 0);
        if (count($frames) >= ($GLOBALS['depth'] ?? 3)) {
            break;
        }
    }
    $sql = preg_replace('/in \([0-9, ?]+\)/', 'in (…)', preg_replace('/\s+/', ' ', $q->sql));
    $log[] = ['sql' => $sql, 'at' => implode(' ← ', $frames) ?: '(сессия, кэш, каркас)', 'ms' => $q->time, 'conn' => $q->connectionName,
        'exact' => preg_replace('/\s+/', ' ', $q->sql).' '.json_encode($q->bindings)];
});

$hit = function (string $url, bool $cold) use ($app, $kernel, $host, $headers, $opt, &$log, &$on): array {
    for ($hop = 0; ; $hop++) {
        Once::flush();
        if ($cold) {
            Cache::flush();
        }
        $request = Request::create('http://'.$host.$url, 'GET');
        $request->headers->set('Accept', 'text/html');
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        $app->instance('request', $request);
        $app['auth']->guard('web')->setUser(App\Users\User::withoutGlobalScopes()->findOrFail((int) $opt['user']));
        $log = [];
        $on = true;
        $started = microtime(true);
        $response = $kernel->handle($request);
        $body = (string) $response->getContent();
        $ms = (microtime(true) - $started) * 1000;
        $on = false;
        $kernel->terminate($request, $response);
        if ($response->isRedirection() && $hop < 3) {
            $to = preg_replace('#^https?://[^/]+#', '', (string) $response->headers->get('Location'));
            printf("  %d → %s (%d мс, запросов %d)\n", $response->getStatusCode(), $to, $ms, count($log));
            $url = $to;

            continue;
        }

        if ($response->getStatusCode() >= 500 && ($error = $response->exception ?? null)) {
            printf("  ошибка: %s (%s:%d)\n", mb_strimwidth($error->getMessage(), 0, 200, '…'), str_replace(dirname(__DIR__).'/', '', $error->getFile()), $error->getLine());
        }

        return ['status' => $response->getStatusCode(), 'ms' => $ms, 'log' => $log, 'kb' => strlen($body) / 1024, 'gz' => strlen(gzencode($body, 6)) / 1024];
    }
};

$line = function (string $label, array $r): void {
    $by = array_count_values(array_column($r['log'], 'conn'));
    ksort($by);
    printf("  %-9s %d  %5.0f мс  запросов %4d (%s)  база %5.0f мс  %6.1f КБ, сжатый %5.1f\n", $label, $r['status'], $r['ms'], count($r['log']),
        implode(', ', array_map(fn ($c, $n) => "$c $n", array_keys($by), $by)), array_sum(array_column($r['log'], 'ms')), $r['kb'], $r['gz']);
};

// Разогрев: первый запрос процесса платит за загрузку классов и компиляцию видов.
$hit($urls[0], false);

foreach ($urls as $url) {
    echo "\n".$url.($headers ? '  ['.implode(', ', array_map(fn ($k, $v) => "$k: $v", array_keys($headers), $headers)).']' : '')."\n";
    $cold = $hit($url, true);
    $line('холодный', $cold);
    $warm = $hit($url, false);
    $line('тёплый', $warm);
    $top = (int) $opt['top'];
    if ($top > 0) {
        // --dups — один и тот же запрос с теми же значениями дважды и больше: то, что уже было прочитано.
        $key = isset($opt['dups']) ? 'exact' : (isset($opt['trace']) ? 'at' : 'sql');
        $groups = [];
        foreach ((isset($opt['cold']) ? $cold : $warm)['log'] as $q) {
            $groups[$q[$key]] ??= [0, 0.0];
            $groups[$q[$key]][0]++;
            $groups[$q[$key]][1] += $q['ms'];
        }
        uasort($groups, fn ($a, $b) => $b[1] <=> $a[1]);
        // --grep=текст — каждый запрос с этим куском и откуда он (7 кадров).
        if (isset($opt['grep'])) {
            foreach ((isset($opt['cold']) ? $cold : $warm)['log'] as $q) {
                if (str_contains($q['exact'], (string) $opt['grep'])) {
                    echo '     · '.$q['at']."\n";
                }
            }

            continue;
        }
        if (isset($opt['dups'])) {
            $groups = array_filter($groups, fn ($g) => $g[0] > 1);
            uasort($groups, fn ($a, $b) => $b[0] <=> $a[0]);
        }
        foreach (array_slice($groups, 0, $top, true) as $what => [$n, $ms]) {
            printf("     ×%-4d %6.1f мс  %s\n", $n, $ms, mb_strimwidth($what, 0, 170, '…'));
        }
    }
}
