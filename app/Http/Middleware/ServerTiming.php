<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Server-Timing: сколько считал сервер, сколько из этого — база и сколько было запросов (`q`, главный признак N+1).
 * Видно в Web Inspector с iPhone (Network → Timing) — мерить, а не крутить. Стоит первым в цепочке и считает от
 * `REQUEST_TIME_FLOAT`: сессия, cookie и биндинги тоже попадают в счёт (у воркера Octane `LARAVEL_START` нет).
 */
class ServerTiming
{
    private static int $queries = 0;

    /** Диспетчер, на который уже подписан счётчик: воркер Octane живёт долго, а диспетчер у запроса бывает новый. */
    private static ?int $listening = null;

    public function handle(Request $request, Closure $next)
    {
        $events = spl_object_id(DB::connection()->getEventDispatcher());
        if (self::$listening !== $events) {
            DB::listen(fn () => self::$queries++);
            self::$listening = $events;
        }
        self::$queries = 0;
        $dbBefore = DB::connection()->totalQueryDuration();
        $started = (float) $request->server('REQUEST_TIME_FLOAT');
        if (! $started || microtime(true) - $started > 60) {
            $started = microtime(true); // у воркера метка могла остаться от старта процесса
        }

        $response = $next($request);
        if (method_exists($response, 'header')) {
            $app = round((microtime(true) - $started) * 1000, 1);
            $db = round(DB::connection()->totalQueryDuration() - $dbBefore, 1);
            $response->header('Server-Timing', 'app;dur='.$app.', db;dur='.$db.', q;desc="'.self::$queries.'"');
        }

        return $response;
    }
}
