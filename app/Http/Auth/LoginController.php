<?php

namespace App\Http\Auth;

use App\Support\Phone;
use App\Support\Surface;
use App\Telegram\Bot;
use App\Telegram\StartLink;
use App\Users\Actions\LeaveImpersonation;
use App\Users\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController
{
    /** Ссылка «Войти через Telegram» — у каждого браузера своя попытка: подтверждённую кнопкой в чате заберёт только он. */
    public function show(Request $request, Bot $bot)
    {
        $token = $bot->username() ? StartLink::login($request) : null;

        return view('auth.login', [
            'telegram' => $token ? $bot->startUrl($token) : null,
            'telegramApp' => $token ? 'tg://resolve?domain='.$bot->username().'&start='.$token : null,
            'telegramToken' => $request->session()->get('telegram.login'),
        ]);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        [$field, $value] = self::identify(trim($data['login']));

        if (! Auth::attempt([$field => $value, 'password' => $data['password']], $request->boolean('remember', true))) {
            throw ValidationException::withMessages(['login' => 'Не подходит логин, телефон, почта или пароль']);
        }

        $request->session()->regenerate();

        return redirect()->intended(self::home());
    }

    public function logout(Request $request, LeaveImpersonation $leave)
    {
        // Вход админа за человека: выходим только здесь, remember_token у человека не трогаем.
        if (Impersonation::active()) {
            $leave($request);

            return redirect('/login');
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * Чем человек назвался: похоже на телефон — телефон, есть @ — почта,
     * иначе логин (покупатели без телефона и почты входят им).
     *
     * @return array{0: string, 1: string}
     */
    public static function identify(string $login): array
    {
        if (Phone::looksLikePhone($login)) {
            return ['phone', Phone::normalize($login) ?? $login];
        }
        if (str_contains($login, '@')) {
            return ['email', mb_strtolower($login)];
        }

        return ['login', mb_strtolower($login)];
    }

    /** Куда после входа: на CRM и стоянке чужого уводим на сайт. */
    public static function home(): string
    {
        $surface = Surface::current();
        $user = Auth::user();
        $allowed = $surface->opensFor($user);

        // Управляющий парковкой, вошедший на сайте или в CRM, — сразу на парковку.
        return $allowed && ! ($user?->isParking() && $surface !== Surface::Park) ? $surface->home() : ($user?->isParking() ? Surface::Park->url(Surface::Park->home()) : Surface::Site->url(Surface::Site->home()));
    }
}
