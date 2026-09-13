<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Server-Timing: сколько считал сервер и сколько из этого — база. Видно в
 * Web Inspector с iPhone (Network → Timing) — мерить, а не крутить.
 */
class ServerTiming
{
    public function handle(Request $request, Closure $next)
    {
        $started = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
        $response = $next($request);
        if (method_exists($response, 'header')) {
            $app = round((microtime(true) - $started) * 1000, 1);
            $db = round(DB::connection()->totalQueryDuration(), 1);
            $response->header('Server-Timing', "app;dur={$app}, db;dur={$db}");
        }

        return $response;
    }
}
