<?php

namespace App\Http\Auth;

use App\Support\Phone;
use App\Support\Surface;
use App\Users\Section;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController
{
    public function show()
    {
        return view('auth.login');
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

    public function logout(Request $request)
    {
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
        $allowed = match ($surface) {
            Surface::Crm => $user?->isStaff(),
            Surface::Park => $user?->canAccess(Section::Park),
            Surface::Site => true,
        };

        // Управляющий парковкой, вошедший на сайте или в CRM, — сразу на парковку.
        return $allowed && ! ($user?->isParking() && $surface !== Surface::Park) ? $surface->home() : ($user?->isParking() ? Surface::Park->url() : Surface::Site->url());
    }
}
