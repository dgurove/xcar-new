<?php

namespace App\Users;

use App\Mail\SystemMail;
use App\Support\Surface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;

/**
 * Письма безопасности и подтверждение почты с no-reply (06.11.2026). Редкие и всегда: «Пароль изменён» — на подтверждённую
 * почту, «Почта изменена» — на прежний подтверждённый адрес: по ним человек узнаёт, что аккаунт угнали. «Вход с нового
 * устройства» не шлём — там подтверждение в Telegram. Подтверждение почты — по нажатию «Подтвердить» в настройках
 * уведомлений: без него уведомления на почту не уходят (опечатка в адресе не бьёт по репутации домена отказами).
 */
final class SecurityLetter
{
    public static function passwordChanged(User $user): void
    {
        if (! $user->mailVerified()) {
            return;
        }
        $html = view('emails.notice', [
            'title' => 'Пароль изменён', 'subtitle' => $user->name.', '.now('Europe/Moscow')->translatedFormat('j F, G:i'),
            'note' => 'Если это были не вы, напишите нам: войти можно по ссылке «Забыли пароль» на странице входа',
            'button' => ['text' => 'Войти', 'url' => Surface::homeFor($user)->url('/login')],
        ])->render();
        SystemMail::queue(SystemMail::make($user->email, $user->name, 'Пароль на xcar изменён', $html));
    }

    public static function emailChanged(User $user, string $old): void
    {
        $html = view('emails.notice', [
            'title' => 'Почта изменена', 'subtitle' => 'Письма теперь будут приходить на '.($user->email ?: 'другой адрес'),
            'note' => 'Если это были не вы, напишите нам',
        ])->render();
        SystemMail::queue(SystemMail::make($old, $user->name, 'Почта в xcar изменена', $html));
    }

    /** Письмо «Подтвердите почту»: не чаще раза в 2 минуты на человека. false — рано или нет адреса. */
    public static function verify(User $user): bool
    {
        if (! $user->email || $user->mailVerified() || ! Cache::add('mail-verify:'.$user->id, true, 120)) {
            return false;
        }
        $url = URL::temporarySignedRoute('mail.verify', now()->addDays(3), ['user' => $user->id, 'hash' => sha1((string) $user->email)]);
        $html = view('emails.notice', [
            'title' => 'Подтвердите почту', 'subtitle' => $user->email, 'preheader' => 'Одна кнопка — и важные уведомления будут приходить сюда',
            'button' => ['text' => 'Подтвердить', 'url' => $url],
            'note' => 'Если вы не указывали эту почту в XCar, просто удалите письмо',
        ])->render();
        SystemMail::queue(SystemMail::make($user->email, $user->name, 'Подтвердите почту в XCar', $html));

        return true;
    }
}
