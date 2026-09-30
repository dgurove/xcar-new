<?php

namespace App\Users\Actions;

use App\Users\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Открыли ссылку и нажали «Войти»: ссылка сгорает одним запросом (второе нажатие ничего не найдёт),
 * права перепроверяются, прежняя сессия браузера сбрасывается, вход — без «запомнить меня».
 */
final class EnterImpersonation
{
    public function __invoke(Request $request, string $token): ?Impersonation
    {
        $burned = Impersonation::where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')->where('expires_at', '>', now())
            ->update(['used_at' => now(), 'ip' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 490)]);
        if (! $burned) {
            return null;
        }
        $as = Impersonation::byToken($token);
        if (! $as->admin || ! Impersonation::allowed($as->admin, $as->user)) {
            $as->update(['ended_at' => now()]);

            return null;
        }

        // Прежний вход этого браузера заканчивается без Auth::logout: тот сменил бы remember_token у его владельца.
        Auth::guard()->logoutCurrentDevice();
        $request->session()->invalidate();
        Auth::login($as->user, false);
        $request->session()->regenerate();
        $request->session()->put(Impersonation::SESSION, $as->id);

        return $as;
    }

    /** Выход: сессия гаснет только в этом браузере, у человека на его устройствах ничего не меняется. */
    public static function leave(Request $request): void
    {
        Impersonation::current()?->update(['ended_at' => now()]);
        Auth::guard()->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
