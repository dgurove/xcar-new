<?php

namespace App\Users\Actions;

use App\Users\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Открыли ссылку и нажали «Войти»: ссылка сгорает одним запросом (второе нажатие ничего не найдёт),
 * права перепроверяются, прежний вход браузера заканчивается, новый — без «запомнить меня».
 */
final class EnterImpersonation
{
    public function __construct(private LeaveImpersonation $leave) {}

    public function __invoke(Request $request, string $token): ?Impersonation
    {
        $burned = Impersonation::where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')->where('expires_at', '>', now())
            ->update(['used_at' => now(), 'ip' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 490)]);
        if (! $burned) {
            return null;
        }
        $as = Impersonation::byToken($token);
        if (! $as->admin || ! $as->user || ! Impersonation::allowed($as->admin, $as->user)) {
            $as->update(['ended_at' => now()]);

            return null;
        }

        // Прежний вход браузера (свой или за другого) заканчивается так же, как «Выйти»: запись закрыта, remember_token
        // его владельца не тронут. Метка кладётся до входа, чтобы слушатели Login видели, что это вход за человека.
        ($this->leave)($request);
        $request->session()->put(Impersonation::SESSION, $as->id);
        Auth::login($as->user, false);
        $request->session()->regenerate();

        return $as;
    }
}
