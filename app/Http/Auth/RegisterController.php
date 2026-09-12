<?php

namespace App\Http\Auth;

use App\Support\Phone;
use App\Support\Surface;
use App\Users\Actions\RegisterUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class RegisterController
{
    public function show()
    {
        return view('auth.register');
    }

    public function store(Request $request, RegisterUser $register)
    {
        $request->merge(['phone' => Phone::normalize($request->input('phone')) ?? $request->input('phone')]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'regex:/^7\d{10}$/', Rule::unique('users', 'phone')],
            'email' => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
        ], [
            'phone.regex' => 'Нужен номер из 11 цифр, например +7 900 123-45-67',
            'phone.unique' => 'С этим номером уже есть аккаунт',
            'email.unique' => 'С этой почтой уже есть аккаунт',
        ]);

        Auth::login($register($data), true);
        $request->session()->regenerate();

        return redirect(Surface::current() === Surface::Site ? '/' : Surface::Site->url());
    }

    /** Отклонённый регистрируется заново: старый аккаунт уходит, чтобы телефон и почта освободились. */
    public function again(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isRejected(), 404);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $user->delete();

        return redirect('/registraciya');
    }
}
