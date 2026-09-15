<?php

namespace App\Http\Auth;

use App\Users\Actions\SetPasswordByLink;
use App\Users\PasswordLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class PasswordController
{
    public function forgot()
    {
        return view('auth.forgot');
    }

    public function send(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        return back()->with('toast', 'Если почта известна, письмо уже идёт');
    }

    public function reset(Request $request, string $token)
    {
        return view('auth.reset', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset($data, function ($user, $password) {
            $user->forceFill(['password' => $password])->save();
            Auth::login($user, true);
        });

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => 'Ссылка устарела, запросите новую']);
        }

        return redirect('/')->with('toast', 'Пароль обновлён');
    }

    // -------------------------------------------------------------- ссылка от менеджера или админа

    /** Экран нового пароля по ссылке, которую выдал человек; сгоревшая — та же страница без формы. */
    public function link(string $token)
    {
        $link = PasswordLink::byToken($token);

        return view('auth.password-link', ['token' => $token, 'live' => $link?->isLive() ?? false, 'user' => $link?->user]);
    }

    public function setByLink(Request $request, string $token, SetPasswordByLink $set)
    {
        $link = PasswordLink::byToken($token);
        abort_unless($link?->isLive(), 404);

        $data = $request->validate(['password' => ['required', 'string', 'min:8', 'confirmed']]);
        $user = $set($link, $data['password']);
        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect(LoginController::home())->with('toast', 'Пароль сохранён');
    }

    /** Смена пароля в профиле: текущий и новый дважды. */
    public function change(Request $request)
    {
        $data = $request->validate([
            'current' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $user = $request->user();
        if (! $user->password || ! Hash::check($data['current'], $user->password)) {
            throw ValidationException::withMessages(['current' => 'Текущий пароль не подходит']);
        }
        $user->forceFill(['password' => $data['password']])->save();

        return back()->with('toast', 'Пароль изменён');
    }
}
