<?php

namespace App\Users\Access;

use App\Users\Impersonation;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Вход по кнопке Telegram (`login_url`, 21.10.2026, владелец: «из Telegram без входа»): Telegram на телефоне открывает
 * ссылку своим браузером, где человека нет, — подпись Telegram и есть вход. Кто нажал, подтверждает сам Telegram:
 * пересланное сообщение чужого не впустит, поэтому «одно устройство» такой вход не считает (решение владельца) — устройство
 * не заводится (`EnrollOnLogin`), приложение на экране «Домой» не выходит. Живёт такой вход 12 часов (`Lock::check`).
 * В полной защите (Telegram, окно 15 минут) — не входит: там устройство заводят сами входы с ключом браузера.
 */
final class TelegramEntry
{
    public const SESSION = 'telegram.view_until';

    public const HOURS = 12;

    /** Помечает вход этого запроса: `EnrollOnLogin` его пропускает. */
    public const MARK = 'telegram.entry';

    public static function login(Request $request, User $user): bool
    {
        if ($user->isRejected() || Impersonation::active() || Auth::id() === $user->id || ($user->underLock() && Lock::enabled())) {
            return false;
        }
        $request->attributes->set(self::MARK, true);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION, now()->addHours(self::HOURS)->getTimestamp());

        return true;
    }

    /** Вход по кнопке Telegram, и его 12 часов прошли. */
    public static function expired(Request $request): bool
    {
        $until = $request->hasSession() ? $request->session()->get(self::SESSION) : null;

        return $until !== null && now()->getTimestamp() > (int) $until;
    }
}
