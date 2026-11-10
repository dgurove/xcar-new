<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Server-Timing: сколько считал сервер, сколько из этого — база и сколько было запросов (`q`, главный признак N+1).
 * Видно в Web Inspector с iPhone (Network → Timing) — мерить, а не крутить. Стоит первым в цепочке и считает от
 * `REQUEST_TIME_FLOAT`: сессия, cookie и биндинги тоже попадают в счёт (у воркера Octane `LARAVEL_START` нет).
 *
 * Медленный ответ (от `SLOW_MS`), лавина запросов (от `MANY_QUERIES`) и 5xx — строкой в `storage/logs/slow-*.log`
 * (10.11.2026, «не грузит система» — а stderr живёт до перезапуска, и ответить «давно ли и почему» было нечем);
 * сводку за день владельцу шлёт `perf:digest`.
 */
class ServerTiming
{
    private static int $queries = 0;

    /** Диспетчер, на который уже подписан счётчик: воркер Octane живёт долго, а диспетчер у запроса бывает новый. */
    private static ?int $listening = null;

    public const SLOW_MS = 2000;

    public const MANY_QUERIES = 150;

    public function handle(Request $request, Closure $next)
    {
        $events = spl_object_id(DB::connection()->getEventDispatcher());
        if (self::$listening !== $events) {
            DB::listen(fn () => self::$queries++);
            self::$listening = $events;
        }
        self::$queries = 0;
        $dbBefore = self::dbTime();
        $started = (float) $request->server('REQUEST_TIME_FLOAT');
        if (! $started || microtime(true) - $started > 60) {
            $started = microtime(true); // у воркера метка могла остаться от старта процесса
        }

        $response = $next($request);
        if (method_exists($response, 'header')) {
            $app = round((microtime(true) - $started) * 1000, 1);
            $db = round(self::dbTime() - $dbBefore, 1);
            $response->header('Server-Timing', 'app;dur='.$app.', db;dur='.$db.', q;desc="'.self::$queries.'"');
            $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
            if ($app >= self::SLOW_MS || self::$queries >= self::MANY_QUERIES || $status >= 500) {
                self::record($request, $status, $app, $db);
            }
        }

        return $response;
    }

    private static function record(Request $request, int $status, float $app, float $db): void
    {
        try {
            Log::channel('slow')->info('slow', [
                'm' => $request->method(),
                'host' => $request->getHost(),
                'path' => '/'.ltrim($request->path(), '/').($request->getQueryString() ? '?'.$request->getQueryString() : ''),
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

    /** Время базы по всем соединениям: сессии и кэш идут через `pgsql_async`. */
    private static function dbTime(): float
    {
        return array_sum(array_map(fn ($c) => $c->totalQueryDuration(), DB::getConnections()));
    }
}
