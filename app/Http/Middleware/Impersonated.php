<?php

namespace App\Http\Middleware;

use App\Users\Actions\LeaveImpersonation;
use App\Users\Actions\RecordImpersonatedRequest;
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

    public function __construct(private LeaveImpersonation $leave, private RecordImpersonatedRequest $record) {}

    public function handle(Request $request, Closure $next)
    {
        $as = Impersonation::current();
        if (! $as) {
            return $next($request);
        }
        if (! $as->isRunning() || ! $as->user->is($request->user())) {
            ($this->leave)($request);

            return $request->expectsJson() ? abort(401) : redirect('/login');
        }
        if (! $request->isMethodSafe() && $request->is(...self::CLOSED)) {
            return $request->expectsJson() ? abort(403) : back()->with('toast', 'При входе за другого недоступно');
        }

        $response = $next($request);

        // Запрос мог сменить вход (новая ссылка в том же окне, выход) — пишем, только если он ещё тот же.
        if ($request->session()->get(Impersonation::SESSION) === $as->id && $response instanceof Response) {
            ($this->record)($as, $request, $response->getStatusCode());
        }

        return $response;
    }
}
