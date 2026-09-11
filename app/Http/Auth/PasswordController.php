<?php

namespace App\Http\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
}
