<?php

namespace App\Http\Auth;

use App\Users\Actions\EnterImpersonation;
use App\Users\Impersonation;
use Illuminate\Http\Request;

/**
 * «Войти как» по ссылке из CRM. GET только показывает, за кого вход: превью ссылки в мессенджере
 * её не сжигает. Сгорает она по кнопке — POST.
 */
class ImpersonationController
{
    public function show(Request $request, string $token)
    {
        $as = Impersonation::byToken($token);

        return view('auth.impersonate', [
            'token' => $token,
            'as' => $as?->isLive() ? $as : null,
            'current' => $request->user(),
        ]);
    }

    public function enter(Request $request, string $token, EnterImpersonation $enter)
    {
        if (! $enter($request, $token)) {
            return redirect("/login/as/{$token}");
        }

        return redirect()->to(LoginController::home());
    }

    public function leave(Request $request)
    {
        $name = Impersonation::current()?->user?->shortName();
        EnterImpersonation::leave($request);

        return redirect('/login')->with('toast', $name ? "Вход как {$name} закончен" : 'Вход закончен');
    }
}
