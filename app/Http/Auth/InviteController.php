<?php

namespace App\Http\Auth;

use App\Support\Phone;
use App\Users\Actions\AcceptInvite;
use App\Users\Invite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Пригласительная ссылка менеджера: гостю — форма, вошедшему — ничего не
 * делаем (покупатель уже в xcar, менеджер видит свою ссылку глазами покупателя).
 */
class InviteController
{
    public const LOGIN_RULE = 'regex:/^(?=.*[a-z])[a-z0-9._-]{3,32}$/';

    public function show(Request $request, Invite $invite)
    {
        $invite->load('manager');
        if ($user = $request->user()) {
            if ($user->isBuyer() && $user->manager_id === $invite->manager_id) {
                return redirect('/')->with('toast', 'Вы уже в xcar');
            }
            // Свою ссылку менеджер смотрит глазами покупателя, сотрудник — любую; остальным тут делать нечего.
            if ($user->id !== $invite->manager_id && ! $user->isStaff()) {
                return redirect('/')->with('toast', 'Вы уже вошли как '.$user->shortName());
            }
        }

        return view('auth.invite', ['invite' => $invite, 'preview' => $request->user() !== null]);
    }

    public function accept(Request $request, Invite $invite, AcceptInvite $accept)
    {
        abort_if($request->user() !== null, 404);
        abort_unless($invite->isActive(), 404);

        $request->merge([
            'login' => mb_strtolower(trim((string) $request->input('login'))),
            'phone' => $request->filled('phone') ? (Phone::normalize($request->input('phone')) ?? $request->input('phone')) : null,
        ]);
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'login' => ['required', 'string', self::LOGIN_RULE, Rule::unique('users', 'login')],
            'password' => ['required', 'string', 'min:8'],
            'avatar' => ['nullable', 'image', 'max:8192'],
            'consent' => ['accepted'],
        ];
        if ($invite->allows('phone')) {
            $rules['phone'] = ['required', 'regex:/^7\d{10}$/', Rule::unique('users', 'phone')];
        }
        if ($invite->allows('email')) {
            // Менеджеру почта не обязательна: покупатели видят его телефон.
            $rules['email'] = [$invite->forManager() ? 'nullable' : 'required', 'email', 'max:190', Rule::unique('users', 'email')];
        }
        $data = $request->validate($rules, [
            'login.regex' => 'Логин — латиницей, от трёх знаков: буквы, цифры, точка',
            'login.unique' => 'Этот логин уже занят',
            'phone.regex' => 'Нужен номер из 11 цифр, например +7 900 123-45-67',
            'phone.unique' => 'С этим номером уже есть аккаунт',
            'email.unique' => 'С этой почтой уже есть аккаунт',
            'consent.accepted' => 'Без согласия зарегистрироваться нельзя',
        ]);

        $user = $accept($invite, $data, $request->file('avatar'));
        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect($user->isManager() ? '/lk' : '/')->with('toast', 'Добро пожаловать, '.$user->name);
    }
}
