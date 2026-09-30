<?php

namespace App\Http\Middleware;

use App\Users\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** «В сети»: seen_at у человека обновляется не чаще раза в минуту — по самому seen_at загруженной модели, без запроса
 * к кэшу на каждую страницу. Админ за него «в сети» не делает. */
class TouchSeen
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && ! Impersonation::active() && (! $user->seen_at || $user->seen_at->lt(now()->subMinute()))) {
            // Через DB, а не Eloquent: updated_at человека от «был в сети» меняться не должен. Соединение без ожидания
            // диска (`pgsql_async`): отметка раз в минуту не должна стоить странице фиксацию на HDD.
            DB::connection('pgsql_async')->table('users')->where('id', $user->id)->update(['seen_at' => now()]);
        }

        return $next($request);
    }
}
