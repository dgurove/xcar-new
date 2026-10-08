<?php

namespace App\Users\Access;

use App\Users\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;

/**
 * Одно устройство без полной защиты (08.10.2026): любой вход менеджера — пароль, Face ID, Telegram, приглашение —
 * делает этот браузер устройством, прежние выходят. В полной защите устройство заводят сами входы, с ключом браузера.
 * Вход по «запомнить меня» устройство не заводит: выкинутый браузер, долго бывший офлайн, вернулся бы по старой cookie
 * и выкинул настоящее; его встречает сторож — метка устарела, выход. «Войти как» — не вход человека (Lock::lite).
 */
final class EnrollOnLogin
{
    public function __construct(private Enroll $enroll) {}

    public function handle(Login $event): void
    {
        if (! app()->bound('request') || ! $event->user instanceof User || Auth::guard($event->guard)->viaRemember()) {
            return;
        }
        $request = request();
        if ($request->hasSession() && Lock::lite($event->user)) {
            ($this->enroll)($request, $event->user);
        }
    }
}
