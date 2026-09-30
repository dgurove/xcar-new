<?php

namespace App\Http\Middleware;

use App\Users\Actions\EnterImpersonation;
use App\Users\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Сессия админа за человека: кончилась (часы, «Выйти» с другого экрана, админ без прав) — выход;
 * пароль, ключи входа и пуш за него закрыты; каждая запись за него — строкой в журнал.
 */
class Impersonated
{
    private const CLOSED = ['account/password', 'passkey/*', 'push/subscription'];

    public function handle(Request $request, Closure $next)
    {
        $as = Impersonation::current();
        if (! $as) {
            return $next($request);
        }
        if (! $as->isRunning() || ! $as->user->is($request->user())) {
            EnterImpersonation::leave($request);

            return $request->expectsJson() ? abort(401) : redirect('/login');
        }
        if (! $request->isMethodSafe() && $request->is(...self::CLOSED)) {
            return $request->expectsJson() ? abort(403) : back()->with('toast', 'При входе за другого недоступно');
        }

        $response = $next($request);

        if (! $request->isMethodSafe() && ! $request->is('login/as/exit')) {
            $as->actions()->create([
                'method' => $request->method(),
                'path' => mb_substr('/'.$request->path(), 0, 500),
                'status' => $response instanceof Response ? $response->getStatusCode() : null,
                'created_at' => now(),
            ]);
        }

        return $response;
    }
}
