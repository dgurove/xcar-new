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

        $login = trim($data['login']);
        $field = Phone::looksLikePhone($login) ? 'phone' : 'email';
        $value = $field === 'phone' ? (Phone::normalize($login) ?? $login) : mb_strtolower($login);

        if (! Auth::attempt([$field => $value, 'password' => $data['password']], $request->boolean('remember', true))) {
            throw ValidationException::withMessages(['login' => 'Не подходит телефон, почта или пароль']);
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

        return $allowed ? $surface->home() : Surface::Site->url();
    }
}
