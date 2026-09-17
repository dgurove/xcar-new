<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** «В сети»: seen_at у человека обновляется не чаще раза в минуту — кэш-замок вместо записи на каждый запрос. */
class TouchSeen
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && Cache::add("seen:{$user->id}", 1, 60)) {
            // Через DB, а не Eloquent: updated_at человека от «был в сети» меняться не должен.
            DB::table('users')->where('id', $user->id)->update(['seen_at' => now()]);
        }

        return $next($request);
    }
}
