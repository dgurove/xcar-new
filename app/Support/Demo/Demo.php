<?php

namespace App\Support\Demo;

use Illuminate\Support\Facades\Auth;

/**
 * Демо-кабинет: смотрит ли сейчас демо-пользователь. Только уже поднятый из сессии пользователь
 * (`hasUser`) — сама проверка не должна запускать вход, иначе область на `User` зациклилась бы.
 */
final class Demo
{
    public static function viewing(): bool
    {
        return Auth::hasUser() && (bool) Auth::user()->is_demo;
    }
}
