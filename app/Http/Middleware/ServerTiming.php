<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Server-Timing: сколько считал сервер, сколько из этого — база и сколько было запросов (`q`, главный признак N+1).
 * Видно в Web Inspector с iPhone (Network → Timing) — мерить, а не крутить. Стоит первым в цепочке и считает от
 * `REQUEST_TIME_FLOAT`: сессия, cookie и биндинги тоже попадают в счёт (у воркера Octane `LARAVEL_START` нет).
 *
 * Фазы (17.11.2026, «почему сервер так долго отдаёт»): `pre` — до найденного маршрута (запуск, общие middleware),
 * `run` — middleware маршрута и контроллер, `view` — отрисовка ответа контроллера в строку; остаток `app` — обратный
 * путь по middleware. Экран, что рисует себя внутри контроллера (ответ фрейму), весь лежит в `run`.
 *
 * Медленный ответ (от `SLOW_MS`), лавина запросов (от `MANY_QUERIES`) и 5xx — строкой в `storage/logs/slow-*.log`
 * (10.11.2026, «не грузит система» — а stderr живёт до перезапуска, и ответить «давно ли и почему» было нечем);
 * сводку за день владельцу шлёт `perf:digest`. Каждый GET страницы — строкой в `storage/logs/perf-*.log` с видом
 * запроса (`kind`): по ним `perf:pages` считает медиану и p90 разделов, до и после правок.
 */
class ServerTiming
{
    private static int $queries = 0;

    /** Диспетчер, на который уже подписан счётчик: воркер Octane живёт долго, а диспетчер у запроса бывает новый. */
    private static ?int $listening = null;

    /** Метки фаз идущего запроса: маршрут найден, контроллер ответил, ответ отрисован. @var array<string, float> */
    private static array $marks = [];

    public const SLOW_MS = 2000;

    public const MANY_QUERIES = 150;

    public function handle(Request $request, Closure $next)
    {
        $dispatcher = DB::connection()->getEventDispatcher();
        if (self::$listening !== spl_object_id($dispatcher)) {
            DB::listen(fn () => self::$queries++);
            $dispatcher->listen(RouteMatched::class, fn () => self::$marks['route'] ??= microtime(true));
            $dispatcher->listen(PreparingResponse::class, fn () => self::$marks['done'] ??= microtime(true));
            $dispatcher->listen(ResponsePrepared::class, fn () => self::$marks['drawn'] ??= microtime(true));
            self::$listening = spl_object_id($dispatcher);
        }
        self::$queries = 0;
        self::$marks = [];
        $dbBefore = self::dbTime();
        $started = (float) $request->server('REQUEST_TIME_FLOAT');
        if (! $started || microtime(true) - $started > 60) {
            $started = microtime(true); // у воркера метка могла остаться от старта процесса
        }

        $response = $next($request);
        if (method_exists($response, 'header')) {
            $app = round((microtime(true) - $started) * 1000, 1);
            $db = round(self::dbTime() - $dbBefore, 1);
            $phases = self::phases($started);
            $timing = 'app;dur='.$app.', db;dur='.$db.', q;desc="'.self::$queries.'"';
            foreach ($phases as $name => $ms) {
                $timing .= ', '.$name.';dur='.$ms;
            }
            $response->header('Server-Timing', $timing);
            $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
            if ($app >= self::SLOW_MS || self::$queries >= self::MANY_QUERIES || $status >= 500) {
                self::record($request, $status, $app, $db);
            }
            // Только удавшиеся: редирект дёшев, 404 картинки без медиатеки (локально) — шум, 5xx — в журнале медленных.
            if ($request->isMethod('GET') && $status === 200) {
                self::page($request, $response, $status, $app, $db, $phases);
            }
        }

        return $response;
    }

    /** @return array<string, float> фазы в мс: что успели отметить */
    private static function phases(float $started): array
    {
        $ms = fn (float $from, float $to) => round(max(0, $to - $from) * 1000, 1);
        $route = self::$marks['route'] ?? null;
        $done = self::$marks['done'] ?? null;
        $drawn = self::$marks['drawn'] ?? null;

        return array_filter([
            'pre' => $route ? $ms($started, $route) : null,
            'run' => $route && $done ? $ms($route, $done) : null,
            'view' => $done && $drawn ? $ms($done, $drawn) : null,
        ], fn ($v) => $v !== null);
    }

    /** Вид запроса: страница целиком или её кусок — по заголовку, которым клиент его просит. */
    public static function kind(Request $request): string
    {
        return match (true) {
            $request->hasHeader('X-Live-Rows') => 'rows',
            $request->hasHeader('X-List') => 'list',
            $request->hasHeader('X-Count') => 'count',
            $request->hasHeader('X-Filter-Pane') => 'pane',
            $request->hasHeader('Turbo-Frame') => 'frame:'.$request->header('Turbo-Frame'),
            $request->prefetch() => 'prefetch',
            // Поток по живому событию (счётчики `/live/badges`, плитка `/offers/N/card`): не страница (18.11.2026).
            str_contains((string) $request->header('Accept'), 'turbo-stream') => 'stream',
            default => 'page',
        };
    }

    private static function page(Request $request, $response, int $status, float $app, float $db, array $phases): void
    {
        try {
            $body = method_exists($response, 'getContent') ? $response->getContent() : null;
            Log::channel('perf')->info('page', [
                'host' => $request->getHost(),
                'path' => self::path($request),
                'kind' => self::kind($request),
                'status' => $status,
                'ms' => (int) $app,
                'db' => (int) $db,
                'q' => self::$queries,
                'kb' => is_string($body) ? (int) round(strlen($body) / 1024) : null,
                'user' => $request->user()?->id,
            ] + array_map('intval', $phases));
        } catch (Throwable) {
            // журнал не роняет ответ
        }
    }

    private static function record(Request $request, int $status, float $app, float $db): void
    {
        try {
            Log::channel('slow')->info('slow', [
                'm' => $request->method(),
                'host' => $request->getHost(),
                'path' => self::path($request),
                'frame' => $request->header('Turbo-Frame'),
                'status' => $status,
                'ms' => (int) $app,
                'db' => (int) $db,
                'q' => self::$queries,
                'user' => $request->user()?->id,
            ]);
        } catch (Throwable) {
            // журнал не роняет ответ
        }
    }

    private static function path(Request $request): string
    {
        return '/'.ltrim($request->path(), '/').($request->getQueryString() ? '?'.$request->getQueryString() : '');
    }

    /** Время базы по всем соединениям: сессии и кэш идут через `pgsql_async`. */
    private static function dbTime(): float
    {
        return array_sum(array_map(fn ($c) => $c->totalQueryDuration(), DB::getConnections()));
    }
}
