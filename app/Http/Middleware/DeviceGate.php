<?php

namespace App\Http\Middleware;

use App\Users\Access\Lock;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Сторож замка менеджера (07.10.2026). Окно прятать мало: цены лежали бы в странице, в кэше Turbo и воркера, поэтому
 * за окном сервер данных не отдаёт вовсе. Окно идёт — всё как обычно, только ответы `no-store`. Окно кончилось, своего
 * устройства нет, Telegram не привязан — экран без содержимого с окном подтверждения (423), фрейм, поток, fetch и
 * предзагрузка — пустой 423 (клиент по нему замерзает сам), запись — назад. Вошли с другого устройства — выход.
 */
class DeviceGate
{
    /** Открыто под замком: выход, сам замок, привязка Telegram, служебное. */
    private const OPEN = ['logout', 'lock/*', 'account/telegram/state', 'sw.js', 'manifest.webmanifest', 'offline', 'up', 'passkey/error', 'login/as/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $state = Lock::state($request);
        if ($state === null) {
            return $next($request);
        }
        if ($state === Lock::KICKED) {
            // Без смены remember_token: «запомнить меня» на новом устройстве человека остаётся рабочим.
            Auth::guard()->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            Cookie::queue(Cookie::forget(Lock::COOKIE, '/', config('session.domain')));

            return $this->html($request) ? redirect('/login')->with('status', 'Вы вошли на другом устройстве') : response()->json(['state' => $state], 401);
        }
        if ($state === Lock::OPEN || $request->is(...self::OPEN)) {
            return self::private($next($request));
        }
        if ($state === Lock::LINK && ! $request->session()->has(Lock::SESSION_LINKING)) {
            $request->session()->put(Lock::SESSION_LINKING, now()->timestamp);
        }
        if (! $request->isMethodSafe()) {
            return self::private($request->expectsJson() || ! $this->html($request) ? response()->json(['state' => $state], 423) : redirect()->back(303));
        }
        if ($this->html($request) && ! $request->headers->has('Turbo-Frame') && strcasecmp((string) $request->header('Sec-Purpose'), 'prefetch') !== 0) {
            return self::private(response()->view('auth.lock', [], 423));
        }

        return self::private(response()->json(['state' => $state], 423));
    }

    /** Ответ менеджеру не ложится ни в кэш браузера, ни в «назад» Safari, ни в экраны воркера. */
    public static function private(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function html(Request $request): bool
    {
        return ! $request->expectsJson() && str_contains((string) $request->header('Accept'), 'text/html');
    }
}
