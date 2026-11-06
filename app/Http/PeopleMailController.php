<?php

namespace App\Http;

use App\Mail\SystemMail;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * «Отправить на почту» (06.11.2026, no-reply): данные входа нового человека и ссылка для нового пароля — письмом на его
 * почту, рядом с «Отправить» и «Скопировать». Пароль на сервере не хранится: шторка данных входа несёт одноразовый ключ,
 * под ним в кэше 15 минут — кому и какой пароль (`AddPerson::credentials`); отправили — ключ гаснет.
 */
class PeopleMailController
{
    public function credentials(Request $request, string $token)
    {
        $c = Cache::pull('credentials-mail:'.$token);
        $user = $c ? User::find($c['user']) : null;
        if (! $user?->email) {
            return back()->with('toast', 'Письмо уже отправлено или ссылка устарела');
        }
        $html = view('emails.credentials', [
            'title' => 'Доступ к XCar', 'subtitle' => $user->name, 'preheader' => 'Данные для входа в XCar',
            'rows' => [[$c['via'], $c['login']], ['Пароль', $c['password']]],
            'button' => ['text' => 'Войти', 'url' => $c['url']], 'note' => 'Пароль можно сменить в профиле после входа',
        ])->render();
        SystemMail::queue(SystemMail::make($user->email, $user->name, 'Доступ к XCar', $html));

        return back()->with('toast', 'Отправили на '.$user->email);
    }

    /** Ссылка для нового пароля: только та, что выдали сейчас (адрес `/password/link/…`), и тому, кому её выдали. */
    public function passwordLink(Request $request, User $user)
    {
        $me = $request->user();
        abort_unless($me->isAdmin() || $user->manager_id === $me->id, 404);
        $url = (string) $request->input('url');
        abort_unless(str_starts_with($url, \App\Support\Surface::Site->url('/password/link/')) && $user->email, 404);
        $html = view('emails.password', [
            'title' => 'Новый пароль', 'subtitle' => $user->name, 'preheader' => 'Ссылка, чтобы задать новый пароль',
            'button' => ['text' => 'Задать пароль', 'url' => $url], 'note' => 'Ссылка работает сутки и один раз. Если пароль не меняли, просто удалите письмо',
        ])->render();
        SystemMail::queue(SystemMail::make($user->email, $user->name, 'Новый пароль на xcar', $html));

        return back()->with('toast', 'Отправили на '.$user->email);
    }
}
