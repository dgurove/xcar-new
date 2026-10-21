<?php

namespace App\Http\Middleware;

use App\Users\Access\Enroll;
use App\Users\Access\Lock;
use App\Users\Access\TelegramEntry;
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
 *
 * Одно устройство без полной защиты (Lock::lite): только выход. Сессия, открытая до включения тумблера, метки не имеет —
 * устройством её делает первая загрузка страницы, а не фоновые запросы: параллельные завели бы два устройства, и браузер
 * выкинул бы сам себя.
 */
class DeviceGate
{
    /** Открыто под замком: выход, сам замок, привязка Telegram, служебное. */
    private const OPEN = ['logout', 'lock/*', 'account/telegram/state', 'sw.js', 'manifest.webmanifest', 'offline', 'offline/loop', 'up', 'passkey/error', 'login/as/*'];

    public function __construct(private Enroll $enroll) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Вход по кнопке Telegram — не устройство (`TelegramEntry`): его страница прежнее не выкидывает.
        if ($this->page($request) && $request->isMethod('GET') && Lock::lite($request->user()) && Lock::token($request) === null
            && ! ($request->hasSession() && $request->session()->has(TelegramEntry::SESSION))) {
            ($this->enroll)($request, $request->user());
        }
        $state = Lock::state($request);
        if ($state === null) {
            return self::lockable($request, $next($request));
        }
        if ($state === Lock::KICKED) {
            // Предзагрузка не выходит: она дошла бы до /login первой и съела причину, а переход показал бы вход без неё.
            if ($this->prefetch($request)) {
                return self::private(response('', 401));
            }
            // Без смены remember_token: «запомнить меня» на новом устройстве человека остаётся рабочим. Причина — в
            // сессию при любом запросе: фрейм и fetch получают 401, и клиент сам уходит на /login, где её и покажут.
            Auth::guard()->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->flash('status', 'Вы вошли на другом устройстве');
            Cookie::queue(Cookie::forget(Lock::COOKIE, '/', config('session.domain')));

            return $this->page($request) ? redirect('/login') : response()->json(['state' => $state], 401);
        }
        if ($state === Lock::OPEN || $request->is(...self::OPEN)) {
            return self::private($next($request));
        }
        if (! $request->isMethodSafe()) {
            return self::private($request->expectsJson() || ! $this->html($request) ? response()->json(['state' => $state], 423) : redirect()->back(303));
        }
        if ($this->page($request)) {
            return self::private(response()->view('auth.lock', [], 423));
        }

        return self::private(response()->json(['state' => $state], 423));
    }

    /**
     * Экран менеджера, которого замок касается, пока тумблер выключен: кэшируется как обычно, но с меткой — воркер при
     * запуске с иконки не показывает его сразу, а сначала ждёт сеть (`sw.js`): включат замок — экран с ценами не мелькнёт.
     */
    private static function lockable(Request $request, Response $response): Response
    {
        if ($request->user()?->underLock()) {
            $response->headers->set('X-Lockable', '1');
        }

        return $response;
    }

    /** Ответ менеджеру не ложится ни в кэш браузера, ни в «назад» Safari, ни в экраны воркера. */
    public static function private(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** Загрузка экрана целиком: не фрейм и не предзагрузка. */
    private function page(Request $request): bool
    {
        return $this->html($request) && ! $request->headers->has('Turbo-Frame') && ! $this->prefetch($request);
    }

    private function prefetch(Request $request): bool
    {
        return strcasecmp((string) $request->header('Sec-Purpose'), 'prefetch') === 0;
    }

    private function html(Request $request): bool
    {
        return ! $request->expectsJson() && str_contains((string) $request->header('Accept'), 'text/html');
    }
}
