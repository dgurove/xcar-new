<?php

namespace App\Billing;

use App\Users\Role;
use App\Users\User;

/**
 * От чьего имени записывается то, что сделал не человек: оплата по ссылке, поступление из выписки,
 * кнопка в Telegram. Первый админ — владелец; источник оплаты и заметка говорят, откуда она на самом деле.
 */
final class Robot
{
    public static function user(): User
    {
        return User::withRole(Role::Admin)->orderBy('id')->firstOrFail();
    }
}
